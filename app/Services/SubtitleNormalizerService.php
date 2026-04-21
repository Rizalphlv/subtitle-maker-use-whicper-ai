<?php

namespace App\Services;

use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use Illuminate\Support\Collection;
use RuntimeException;

class SubtitleNormalizerService
{
    /**
     * Normalize all subtitles for a video by applying timestamp offsets.
     * 
     * ⚠️ CRITICAL: Each chunk starts at 00:00 in Whisper output → MUST apply offset
     * Each chunk's offset = chunk_index * 60 seconds
     * 
     * @param int $videoId Video ID
     * @param string $language Language code (e.g., 'en', 'id')
     * @return array Normalized segments array: [{"start": float, "end": float, "text": string, "index": int}, ...]
     * @throws RuntimeException If video, chunks, or subtitles not found
     */
    public function normalize(int $videoId, string $language = 'en'): array
    {
        $video = Video::findOrFail($videoId);

        // Get all audio chunks sorted by chunk_index (⚠️ CRITICAL: Chunk Ordering)
        $chunks = AudioChunk::where('video_id', $videoId)
            ->orderBy('chunk_index')
            ->get();

        if ($chunks->isEmpty()) {
            throw new RuntimeException("No audio chunks found for video {$videoId}");
        }

        // Get all subtitles for this video and language (excluding merged records: chunk_index = -1)
        $subtitles = Subtitle::where('video_id', $videoId)
            ->where('language', $language)
            ->where('chunk_index', '>=', 0)  // Skip merged records (chunk_index = -1)
            ->orderBy('chunk_index')
            ->get();

        if ($subtitles->isEmpty()) {
            throw new RuntimeException("No subtitles found for video {$videoId} in language {$language}");
        }

        $allSegments = [];

        // ─────────────────────────────────────────────────────────────────────────
        // Apply offset to each subtitle chunk
        // ─────────────────────────────────────────────────────────────────────────
        foreach ($subtitles as $subtitle) {
            // Find corresponding audio chunk to get start_time
            $audioChunk = $chunks->firstWhere('chunk_index', $subtitle->chunk_index);

            if (!$audioChunk) {
                throw new RuntimeException(
                    "Audio chunk not found for chunk_index {$subtitle->chunk_index} in video {$videoId}"
                );
            }

            $chunkStartTime = $audioChunk->start_time; // in seconds

            // Get raw segments from Whisper output
            $rawSegments = $subtitle->raw_transcript ?? [];

            // Apply offset to each segment
            foreach ($rawSegments as $segment) {
                $allSegments[] = [
                    'start' => $segment['start'] + $chunkStartTime,
                    'end' => $segment['end'] + $chunkStartTime,
                    'text' => $segment['text'],
                    'chunk_index' => $subtitle->chunk_index,
                ];
            }
        }

        if (empty($allSegments)) {
            throw new RuntimeException("No segments found after normalization for video {$videoId}");
        }

        // ─────────────────────────────────────────────────────────────────────────
        // Sort globally by start time (⚠️ CRITICAL: Subtitle Merge Logic)
        // ─────────────────────────────────────────────────────────────────────────
        usort($allSegments, function ($a, $b) {
            if (abs($a['start'] - $b['start']) < 0.001) { // Handle float precision
                return 0;
            }
            return $a['start'] < $b['start'] ? -1 : 1;
        });

        // ─────────────────────────────────────────────────────────────────────────
        // Re-index segments (1-indexed for .srt format)
        // ─────────────────────────────────────────────────────────────────────────
        $normalizedSegments = [];
        foreach ($allSegments as $index => $segment) {
            $normalizedSegments[] = [
                'index' => $index + 1,
                'start' => $segment['start'],
                'end' => $segment['end'],
                'text' => $segment['text'],
                'chunk_index' => $segment['chunk_index'],
            ];
        }

        // \Log::info('SubtitleNormalizerService: normalization complete', [
        //     'video_id' => $videoId,
        //     'language' => $language,
        //     'segment_count' => count($normalizedSegments),
        //     'total_duration' => end($normalizedSegments)['end'] ?? 0,
        // ]);

        return $normalizedSegments;
    }

    /**
     * Apply offset to a single segment.
     * 
     * @param array $segment Segment with 'start' and 'end' keys (seconds, float)
     * @param float $offsetSeconds Offset in seconds
     * @return array Segment with adjusted timestamps
     */
    public function applyOffset(array $segment, float $offsetSeconds): array
    {
        return [
            'start' => $segment['start'] + $offsetSeconds,
            'end' => $segment['end'] + $offsetSeconds,
            'text' => $segment['text'],
        ];
    }

    /**
     * Validate segment format.
     * 
     * @param array $segment
     * @return bool
     */
    public function isValidSegment(array $segment): bool
    {
        return isset($segment['start'], $segment['end'], $segment['text'])
            && is_numeric($segment['start'])
            && is_numeric($segment['end'])
            && is_string($segment['text'])
            && $segment['start'] >= 0
            && $segment['end'] > $segment['start'];
    }

    /**
     * Convert seconds (float) to SRT timecode format.
     * 
     * Example: 65.5 → "00:01:05,500"
     * 
     * @param float $seconds
     * @return string
     */
    public function secondsToSrtTime(float $seconds): string
    {
        $hours = intdiv((int)$seconds, 3600);
        $minutes = intdiv((int)$seconds % 3600, 60);
        $secs = (int)$seconds % 60;
        $millis = (int)(($seconds - (int)$seconds) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }
}
