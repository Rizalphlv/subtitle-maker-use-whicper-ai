<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SpeechDetectionService
{
    private const SILENCE_THRESHOLD_DB = '-40dB';
    private const MIN_SILENCE_DURATION = '0.5';
    private const SILENCE_RATIO_SKIP = 0.90;
    private const SILENCE_RATIO_WITH_LOW_ENERGY_SKIP = 0.85;
    private const LOW_MEAN_VOLUME_DB = -45.0;
    private const LOW_MAX_VOLUME_DB = -20.0;

    public function shouldSkipBeforeTranscription(string $audioPath): bool
    {
        $duration = $this->getAudioDuration($audioPath);

        if ($duration <= 0.0) {
            Log::warning('SpeechDetectionService: invalid audio duration, keeping chunk for safety', [
                'audio_path' => $audioPath,
                'duration' => $duration,
            ]);

            return false;
        }

        $silenceOutput = $this->runSilenceDetect($audioPath);
        $silenceRatio = $this->parseSilenceRatio($silenceOutput, $duration);

        if ($silenceRatio >= self::SILENCE_RATIO_SKIP) {
            Log::info('SpeechDetectionService: chunk skipped by silence ratio', [
                'audio_path' => $audioPath,
                'silence_ratio' => round($silenceRatio, 4),
            ]);

            return true;
        }

        if ($silenceRatio < self::SILENCE_RATIO_WITH_LOW_ENERGY_SKIP) {
            return false;
        }

        $volumeOutput = $this->runVolumeDetect($audioPath);
        $meanVolume = $this->parseVolumeMetric($volumeOutput, 'mean_volume');
        $maxVolume = $this->parseVolumeMetric($volumeOutput, 'max_volume');

        $shouldSkip = $meanVolume !== null
            && $meanVolume <= self::LOW_MEAN_VOLUME_DB
            && ($maxVolume === null || $maxVolume <= self::LOW_MAX_VOLUME_DB);

        if ($shouldSkip) {
            Log::info('SpeechDetectionService: chunk skipped by silence and low energy', [
                'audio_path' => $audioPath,
                'silence_ratio' => round($silenceRatio, 4),
                'mean_volume' => $meanVolume,
                'max_volume' => $maxVolume,
            ]);
        }

        return $shouldSkip;
    }

    public function filterMeaningfulSegments(array $segments): array
    {
        $filteredSegments = [];

        foreach ($segments as $segment) {
            if (!isset($segment['start'], $segment['end'], $segment['text'])) {
                continue;
            }

            $text = $this->normalizeText((string) $segment['text']);
            if (!$this->isMeaningfulSpeechText($text)) {
                continue;
            }

            $filteredSegments[] = [
                'start' => (float) $segment['start'],
                'end' => (float) $segment['end'],
                'text' => $text,
            ];
        }

        if (!$this->hasMeaningfulSpeech($filteredSegments)) {
            return [];
        }

        return $filteredSegments;
    }

    public function hasMeaningfulSpeech(array $segments): bool
    {
        if ($segments === []) {
            return false;
        }

        $totalWords = 0;
        $hasSentenceLikeSegment = false;
        $hasSubstantiveWord = false;

        foreach ($segments as $segment) {
            $text = $this->normalizeText((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $words = $this->extractWords($text);
            $wordCount = count($words);
            $totalWords += $wordCount;

            if ($wordCount >= 3 || preg_match('/[.!?]/', $text) === 1) {
                $hasSentenceLikeSegment = true;
            }

            foreach ($words as $word) {
                if (mb_strlen($word) >= 4) {
                    $hasSubstantiveWord = true;
                    break;
                }
            }
        }

        return $hasSentenceLikeSegment || $totalWords >= 2 || $hasSubstantiveWord;
    }

    public function isMeaningfulSpeechText(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^[^A-Za-z0-9]+$/', $normalized) === 1) {
            return false;
        }

        if ($this->isFillerOnlyText($normalized)) {
            return false;
        }

        return true;
    }

    public function parseSilenceRatio(string $ffmpegOutput, float $duration): float
    {
        if ($duration <= 0.0) {
            return 0.0;
        }

        preg_match_all('/silence_start:\s*([0-9]+(?:\.[0-9]+)?)/', $ffmpegOutput, $startMatches);
        preg_match_all('/silence_end:\s*([0-9]+(?:\.[0-9]+)?)/', $ffmpegOutput, $endMatches);

        $starts = array_map('floatval', $startMatches[1] ?? []);
        $ends = array_map('floatval', $endMatches[1] ?? []);

        $silenceDuration = 0.0;
        $pairs = max(count($starts), count($ends));

        for ($i = 0; $i < $pairs; $i++) {
            $start = $starts[$i] ?? null;
            $end = $ends[$i] ?? $duration;

            if ($start === null) {
                $start = 0.0;
            }

            if ($end < $start) {
                continue;
            }

            $silenceDuration += ($end - $start);
        }

        return min(1.0, max(0.0, $silenceDuration / $duration));
    }

    public function parseVolumeMetric(string $ffmpegOutput, string $metric): ?float
    {
        if (preg_match('/' . preg_quote($metric, '/') . ':\s*(-?[0-9]+(?:\.[0-9]+)?) dB/', $ffmpegOutput, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }

    protected function normalizeText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text));

        return $normalized === null ? trim($text) : $normalized;
    }

    protected function extractWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}\']+/u', $text, $matches);

        return $matches[0] ?? [];
    }

    protected function isFillerOnlyText(string $text): bool
    {
        $candidate = strtolower($text);
        $candidate = preg_replace('/[^a-z\s]/', ' ', $candidate);
        $candidate = preg_replace('/\s+/', ' ', trim((string) $candidate));

        if ($candidate === '') {
            return true;
        }

        return preg_match('/^(?:(?:ah+|uh+|hm+|hmm+|mm+|mmm+|oh+|aa+|oo+|eh+)(?:\s+|$))+$/i', $candidate) === 1;
    }

    protected function getAudioDuration(string $audioPath): float
    {
        $command = [
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $audioPath,
        ];

        $process = new Process($command);
        $process->setTimeout(120);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            throw new RuntimeException(
                "FFprobe duration check failed: {$exception->getMessage()}\nSTDERR: {$process->getErrorOutput()}"
            );
        }

        return (float) trim($process->getOutput());
    }

    protected function runSilenceDetect(string $audioPath): string
    {
        $command = [
            'ffmpeg',
            '-i', $audioPath,
            '-af', 'silencedetect=n=' . self::SILENCE_THRESHOLD_DB . ':d=' . self::MIN_SILENCE_DURATION,
            '-f', 'null',
            '-',
        ];

        return $this->runFfmpegAnalysis($command, 'silencedetect');
    }

    protected function runVolumeDetect(string $audioPath): string
    {
        $command = [
            'ffmpeg',
            '-i', $audioPath,
            '-af', 'volumedetect',
            '-f', 'null',
            '-',
        ];

        return $this->runFfmpegAnalysis($command, 'volumedetect');
    }

    protected function runFfmpegAnalysis(array $command, string $analysisType): string
    {
        $process = new Process($command);
        $process->setTimeout(300);
        $process->setIdleTimeout(120);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            throw new RuntimeException(
                "FFmpeg {$analysisType} failed: {$exception->getMessage()}\nSTDERR: {$process->getErrorOutput()}"
            );
        }

        return $process->getErrorOutput() . "\n" . $process->getOutput();
    }
}