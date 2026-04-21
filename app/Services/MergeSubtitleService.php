<?php

namespace App\Services;

use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MergeSubtitleService
{
    protected SubtitleNormalizerService $normalizer;

    public function __construct(SubtitleNormalizerService $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * Merge all chunk transcripts into a single subtitle record.
     * 
     * ⚠️ CRITICAL: Subtitle Merge Logic
     * - Apply offset (handled by SubtitleNormalizerService)
     * - Sort globally (handled by SubtitleNormalizerService)
     * - Re-index (handled by SubtitleNormalizerService)
     * 
     * @param int $videoId Video ID
     * @param string $language Language code (e.g., 'en', 'id')
     * @param string $type Type of subtitle (original/translated)
     * @return Subtitle Merged subtitle record
     * @throws RuntimeException If merge fails
     */
    public function merge(int $videoId, string $language = 'en', string $type = 'original'): Subtitle
    {
        $video = Video::findOrFail($videoId);

        // \Log::info('MergeSubtitleService: starting merge', [
        //     'video_id' => $videoId,
        //     'language' => $language,
        //     'type' => $type,
        // ]);

        // Get all audio chunks to validate chunk count
        $totalChunks = AudioChunk::where('video_id', $videoId)->count();
        if ($totalChunks === 0) {
            throw new RuntimeException("No audio chunks found for video {$videoId}");
        }

        // Check all chunks have been transcribed
        $transcodedChunks = AudioChunk::where('video_id', $videoId)
            ->where('status', 'transcribed')
            ->count();

        if ($transcodedChunks !== $totalChunks) {
            throw new RuntimeException(
                "Not all chunks transcribed: {$transcodedChunks}/{$totalChunks} for video {$videoId}"
            );
        }

        // ─────────────────────────────────────────────────────────────────────────
        // Get normalized segments using SubtitleNormalizerService
        // ─────────────────────────────────────────────────────────────────────────
        $normalizedSegments = $this->normalizer->normalize($videoId, $language);

        // ─────────────────────────────────────────────────────────────────────────
        // Validate merged timeline (check for gaps/overlaps)
        // ─────────────────────────────────────────────────────────────────────────
        $this->validateTimeline($normalizedSegments);

        // ─────────────────────────────────────────────────────────────────────────
        // Extract segment data for storage (remove chunk_index, keep index)
        // ─────────────────────────────────────────────────────────────────────────
        $mergedSegments = array_map(function ($segment) {
            return [
                'index' => $segment['index'],
                'start' => $segment['start'],
                'end' => $segment['end'],
                'text' => $segment['text'],
            ];
        }, $normalizedSegments);

        // ─────────────────────────────────────────────────────────────────────────
        // Store merged subtitle record
        // Chunk_index = -1 indicates this is a merged record (not a chunk)
        // ─────────────────────────────────────────────────────────────────────────
        $mergedSubtitle = Subtitle::updateOrCreate(
            [
                'video_id' => $videoId,
                'chunk_index' => -1,  // -1 = merged record marker
                'language' => $language,
                'type' => $type,
            ],
            [
                'raw_transcript' => $mergedSegments,
                'status' => 'transcribed',
            ]
        );

        // \Log::info('MergeSubtitleService: merge complete', [
        //     'video_id' => $videoId,
        //     'subtitle_id' => $mergedSubtitle->id,
        //     'language' => $language,
        //     'type' => $type,
        //     'segment_count' => count($mergedSegments),
        //     'duration_seconds' => end($mergedSegments)['end'] ?? 0,
        // ]);

        return $mergedSubtitle;
    }

    /**
     * Validate merged timeline for issues.
     * 
     * @param array $segments Normalized segments
     * @throws RuntimeException If critical validation fails
     */
    protected function validateTimeline(array $segments): void
    {
        if (empty($segments)) {
            throw new RuntimeException('Cannot merge empty segments');
        }

        $gaps = [];
        $overlaps = [];

        for ($i = 0; $i < count($segments) - 1; $i++) {
            $current = $segments[$i];
            $next = $segments[$i + 1];

            // Check for gap (next starts after current ends)
            $gap = $next['start'] - $current['end'];
            if ($gap > 0.1) {  // Allow 100ms tolerance
                $gaps[] = [
                    'between_index' => $current['index'] . '-' . $next['index'],
                    'gap_seconds' => round($gap, 3),
                ];
            }

            // Check for overlap (next starts before current ends)
            if ($next['start'] < $current['end'] - 0.01) {  // Allow 10ms tolerance
                $overlaps[] = [
                    'between_index' => $current['index'] . '-' . $next['index'],
                    'overlap_seconds' => round($current['end'] - $next['start'], 3),
                ];
            }
        }

        // Log gaps and overlaps (non-critical)
        if (!empty($gaps)) {
            Log::warning('MergeSubtitleService: gaps detected in timeline', [
                'gap_count' => count($gaps),
                'gaps' => $gaps,
            ]);
        }

        if (!empty($overlaps)) {
            Log::warning('MergeSubtitleService: overlaps detected in timeline', [
                'overlap_count' => count($overlaps),
                'overlaps' => $overlaps,
            ]);
        }
    }

    /**
     * Get merged subtitle for a video (convenience method).
     * 
     * @param int $videoId
     * @param string $language
     * @param string $type
     * @return Subtitle|null
     */
    public function getMerged(int $videoId, string $language = 'en', string $type = 'original'): ?Subtitle
    {
        return Subtitle::where('video_id', $videoId)
            ->where('chunk_index', -1)
            ->where('language', $language)
            ->where('type', $type)
            ->first();
    }

    /**
     * Get segment count for merged subtitle.
     * 
     * @param int $videoId
     * @param string $language
     * @return int
     */
    public function getSegmentCount(int $videoId, string $language = 'en'): int
    {
        $merged = $this->getMerged($videoId, $language);
        return $merged ? count($merged->raw_transcript ?? []) : 0;
    }

    /**
     * Get total duration of merged subtitle in seconds.
     * 
     * @param int $videoId
     * @param string $language
     * @return float
     */
    public function getTotalDuration(int $videoId, string $language = 'en'): float
    {
        $merged = $this->getMerged($videoId, $language);
        if (!$merged || empty($merged->raw_transcript)) {
            return 0.0;
        }

        $segments = is_array($merged->raw_transcript) ? $merged->raw_transcript : $merged->raw_transcript->toArray();
        $lastSegment = end($segments);
        return $lastSegment['end'] ?? 0.0;
    }
}
