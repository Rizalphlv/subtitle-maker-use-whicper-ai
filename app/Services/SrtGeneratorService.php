<?php

namespace App\Services;

use App\Models\Subtitle;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SrtGeneratorService
{
    protected SubtitleNormalizerService $normalizer;

    public function __construct(SubtitleNormalizerService $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * Generate SRT file from merged subtitle and store in MinIO.
     * 
     * SRT Format:
     * ```
     * 1
     * 00:00:01,000 --> 00:00:03,000
     * Hello world
     * 
     * 2
     * 00:00:03,500 --> 00:00:05,000
     * Second subtitle
     * ```
     * 
     * @param int $videoId Video ID
     * @param string $language Language code (e.g., 'en', 'id')
     * @param string $type Subtitle type (original/translated)
     * @return string MinIO path where SRT was stored
     * @throws RuntimeException If generation fails
     */
    public function generate(
        int $videoId,
        string $language = 'en',
        string $type = 'original'
    ): string {
        $video = Video::findOrFail($videoId);

        // \Log::info('SrtGeneratorService: starting SRT generation', [
        //     'video_id' => $videoId,
        //     'language' => $language,
        //     'type' => $type,
        // ]);

        // Get merged subtitle (chunk_index = -1)
        $subtitle = Subtitle::where('video_id', $videoId)
            ->where('chunk_index', -1)
            ->where('language', $language)
            ->where('type', $type)
            ->firstOrFail();

        // Get segments
        $segments = $subtitle->raw_transcript ?? [];

        if (empty($segments)) {
            throw new RuntimeException(
                "No segments found for video {$videoId}, language {$language}, type {$type}"
            );
        }

        // ─────────────────────────────────────────────────────────────────────────
        // Build SRT content
        // ─────────────────────────────────────────────────────────────────────────
        $srtContent = $this->buildSrtContent($segments);

        // ─────────────────────────────────────────────────────────────────────────
        // Determine MinIO path
        // ─────────────────────────────────────────────────────────────────────────
        $minioPath = $this->buildMinionPath($videoId, $language, $type);

        // ─────────────────────────────────────────────────────────────────────────
        // Store in MinIO
        // ─────────────────────────────────────────────────────────────────────────
        try {
            Storage::disk('minio')->put($minioPath, $srtContent);

            // \Log::info('SrtGeneratorService: SRT stored successfully', [
            //     'video_id' => $videoId,
            //     'language' => $language,
            //     'type' => $type,
            //     'minio_path' => $minioPath,
            //     'segment_count' => count($segments),
            //     'file_size' => strlen($srtContent),
            // ]);
        } catch (\Exception $e) {
            Log::error('SrtGeneratorService: failed to store SRT', [
                'video_id' => $videoId,
                'language' => $language,
                'type' => $type,
                'minio_path' => $minioPath,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Failed to store SRT file in MinIO: {$e->getMessage()}"
            );
        }

        // ─────────────────────────────────────────────────────────────────────────
        // Update subtitle record with path
        // ─────────────────────────────────────────────────────────────────────────
        $subtitle->update(['path' => $minioPath]);

        Log::info('SrtGeneratorService: SRT generation complete', [
            'video_id' => $videoId,
            'subtitle_id' => $subtitle->id,
            'language' => $language,
            'type' => $type,
            'segment_count' => count($segments),
        ]);

        return $minioPath;
    }

    /**
     * Build SRT content from segments.
     * 
     * ⚠️ CRITICAL: SRT Format Compliance
     * - Sequence numbers (1, 2, 3, ...)
     * - Timestamps: HH:MM:SS,mmm --> HH:MM:SS,mmm
     * - Text content
     * - Blank line between entries
     * 
     * @param array $segments Normalized segments
     * @return string SRT content
     */
    protected function buildSrtContent(array $segments): string
    {
        $lines = [];

        foreach ($segments as $segment) {
            // Sequence number
            $lines[] = (string)$segment['index'];

            // Timestamps
            $startTime = $this->normalizer->secondsToSrtTime($segment['start']);
            $endTime = $this->normalizer->secondsToSrtTime($segment['end']);
            $lines[] = "{$startTime} --> {$endTime}";

            // Text content (split multi-line text)
            $textLines = explode("\n", $segment['text']);
            foreach ($textLines as $textLine) {
                $lines[] = trim($textLine);
            }

            // Blank line between entries
            $lines[] = '';
        }

        // Join with newlines and remove trailing blank line
        $content = implode("\n", $lines);
        return rtrim($content) . "\n";  // Ensure file ends with newline
    }

    /**
     * Build MinIO path for SRT file.
     * 
     * Path structure:
     * /videos/{video_id}/subtitles/original.srt
     * /videos/{video_id}/subtitles/{language}_translated.srt
     * 
     * @param int $videoId Video ID
     * @param string $language Language code
     * @param string $type Subtitle type
     * @return string MinIO path
     */
    protected function buildMinionPath(int $videoId, string $language, string $type): string
    {
        if ($type === 'original') {
            return "videos/{$videoId}/subtitles/{$language}.srt";
        }

        // Translated: include language in filename
        return "videos/{$videoId}/subtitles/{$language}_translated.srt";
    }

    /**
     * Get SRT content from MinIO.
     * 
     * @param string $minioPath MinIO path to SRT file
     * @return string SRT content
     * @throws RuntimeException If file not found
     */
    public function getSrtContent(string $minioPath): string
    {
        try {
            return Storage::disk('minio')->get($minioPath);
        } catch (\Exception $e) {
            throw new RuntimeException(
                "Failed to retrieve SRT file from MinIO at {$minioPath}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Validate SRT content format.
     * 
     * @param string $content SRT content
     * @return bool
     */
    public function validateSrtContent(string $content): bool
    {
        $lines = explode("\n", trim($content));

        if (count($lines) < 4) {  // Minimum: 1 sequence, 1 timestamp, 1 text, 1 blank
            return false;
        }

        $currentIndex = 1;
        $i = 0;

        while ($i < count($lines)) {
            // Expect sequence number
            if ((int)trim($lines[$i]) !== $currentIndex) {
                return false;
            }
            $i++;

            if ($i >= count($lines)) {
                return false;
            }

            // Expect timestamp line
            $timestampLine = trim($lines[$i]);
            if (!preg_match('/^\d{2}:\d{2}:\d{2},\d{3}\s+-->\s+\d{2}:\d{2}:\d{2},\d{3}$/', $timestampLine)) {
                return false;
            }
            $i++;

            if ($i >= count($lines)) {
                return false;
            }

            // Expect at least one text line
            $textLine = trim($lines[$i]);
            if (empty($textLine)) {
                return false;
            }
            $i++;

            // Skip text lines and blank line
            while ($i < count($lines) && trim($lines[$i]) !== '') {
                $i++;
            }
            $i++;  // Skip blank line

            $currentIndex++;
        }

        return true;
    }

    /**
     * Parse SRT content to extract segments.
     * 
     * Useful for validation or debugging.
     * 
     * @param string $content SRT content
     * @return array Parsed segments
     */
    public function parseSrtContent(string $content): array
    {
        $segments = [];
        $lines = explode("\n", trim($content));

        $i = 0;
        while ($i < count($lines)) {
            $line = trim($lines[$i]);

            // Skip blank lines
            if (empty($line)) {
                $i++;
                continue;
            }

            // Index
            $index = (int)$line;
            $i++;

            if ($i >= count($lines)) {
                break;
            }

            // Timestamp
            $timestampLine = trim($lines[$i]);
            preg_match('/(\d{2}):(\d{2}):(\d{2}),(\d{3})\s+-->\s+(\d{2}):(\d{2}):(\d{2}),(\d{3})/', $timestampLine, $matches);
            $startSeconds = (int)$matches[1] * 3600 + (int)$matches[2] * 60 + (int)$matches[3] + (int)$matches[4] / 1000;
            $endSeconds = (int)$matches[5] * 3600 + (int)$matches[6] * 60 + (int)$matches[7] + (int)$matches[8] / 1000;
            $i++;

            if ($i >= count($lines)) {
                break;
            }

            // Text
            $textLines = [];
            while ($i < count($lines) && trim($lines[$i]) !== '') {
                $textLines[] = trim($lines[$i]);
                $i++;
            }

            $segments[] = [
                'index' => $index,
                'start' => $startSeconds,
                'end' => $endSeconds,
                'text' => implode("\n", $textLines),
            ];
        }

        return $segments;
    }
}
