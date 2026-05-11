<?php

namespace App\Jobs;

use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupVideoProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Video $video;

    public $tries = 3;
    public $timeout = 600;  // 10 minutes (cleanup should be quick)

    public function __construct(Video $video)
    {
        $this->video = $video;
        $this->queue = 'default';
    }

    public function handle(): void
    {
        $videoId = $this->video->id;

        Log::info("CleanupVideoProcessingJob: Starting cleanup for video_id={$videoId}");

        try {
            // ── Step 1: Verify Final Subtitles Exist ──────────────────────────────────
            Log::info("CleanupVideoProcessingJob: Verifying final subtitle files for video_id={$videoId}");
            $this->verifyFinalSubtitles($videoId);

            // ── Step 2: Delete Temporary Audio Chunks ─────────────────────────────────
            Log::info("CleanupVideoProcessingJob: Deleting temporary audio chunks for video_id={$videoId}");
            $deletedCount = $this->deleteTemporaryChunks($videoId);
            Log::info("CleanupVideoProcessingJob: Deleted {$deletedCount} audio chunks for video_id={$videoId}");

            // ── Step 3: Delete Original Audio File ────────────────────────────────────
            Log::info("CleanupVideoProcessingJob: Deleting original audio for video_id={$videoId}");
            $this->deleteOriginalAudio($videoId);

            // ── Step 4: Delete Original Video File ────────────────────────────────────
            Log::info("CleanupVideoProcessingJob: Deleting original video for video_id={$videoId}");
            $this->deleteOriginalVideo($videoId);

            Log::info("CleanupVideoProcessingJob: Cleanup completed successfully for video_id={$videoId}");

        } catch (\Exception $exception) {
            Log::error("CleanupVideoProcessingJob: Failed for video_id={$videoId}", [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * Verify that final subtitle files exist in MinIO.
     *
     * @throws \RuntimeException
     */
    protected function verifyFinalSubtitles(int $videoId): void
    {
        $this->video->refresh();

        // Check original language subtitle
        $originalSubtitle = Subtitle::where('video_id', $videoId)
            ->where('chunk_index', -1)
            ->where('type', 'original')
            ->first();

        if (!$originalSubtitle || !$originalSubtitle->path) {
            throw new \RuntimeException("Original subtitle not found for video_id={$videoId}");
        }

        if (!Storage::disk('minio')->exists($originalSubtitle->path)) {
            throw new \RuntimeException("Original subtitle file missing in MinIO: {$originalSubtitle->path}");
        }

        Log::info("CleanupVideoProcessingJob: Verified original subtitle", [
            'video_id' => $videoId,
            'path' => $originalSubtitle->path,
            'size' => Storage::disk('minio')->size($originalSubtitle->path),
        ]);

        // Check translated subtitle if target language is not English
        if ($this->video->target_language !== 'en') {
            $translatedSubtitle = Subtitle::where('video_id', $videoId)
                ->where('chunk_index', -1)
                ->where('type', 'translated')
                ->first();

            if (!$translatedSubtitle || !$translatedSubtitle->path) {
                throw new \RuntimeException("Translated subtitle not found for video_id={$videoId}");
            }

            if (!Storage::disk('minio')->exists($translatedSubtitle->path)) {
                throw new \RuntimeException("Translated subtitle file missing in MinIO: {$translatedSubtitle->path}");
            }

            Log::info("CleanupVideoProcessingJob: Verified translated subtitle", [
                'video_id' => $videoId,
                'path' => $translatedSubtitle->path,
                'size' => Storage::disk('minio')->size($translatedSubtitle->path),
            ]);
        }
    }

    /**
     * Delete temporary audio chunks from MinIO.
     *
     * Returns the count of deleted chunks.
     */
    protected function deleteTemporaryChunks(int $videoId): int
    {
        $chunks = AudioChunk::where('video_id', $videoId)->get();

        $deletedCount = 0;
        foreach ($chunks as $chunk) {
            if ($chunk->path && Storage::disk('minio')->exists($chunk->path)) {
                try {
                    Storage::disk('minio')->delete($chunk->path);
                    $deletedCount++;

                    Log::debug("CleanupVideoProcessingJob: Deleted chunk", [
                        'video_id' => $videoId,
                        'chunk_index' => $chunk->chunk_index,
                        'path' => $chunk->path,
                    ]);
                } catch (\Exception $e) {
                    Log::warning("CleanupVideoProcessingJob: Failed to delete chunk", [
                        'video_id' => $videoId,
                        'chunk_index' => $chunk->chunk_index,
                        'path' => $chunk->path,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail the entire cleanup if one chunk deletion fails
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Delete original audio file from MinIO.
     * 
     * Note: Only call this if you're sure you don't need the audio anymore.
     * By default, this is not called.
     */
    protected function deleteOriginalAudio(int $videoId): void
    {
        $audioPath = "videos/{$videoId}/audio.mp3";

        if (Storage::disk('minio')->exists($audioPath)) {
            try {
                Storage::disk('minio')->delete($audioPath);

                Log::info("CleanupVideoProcessingJob: Deleted original audio", [
                    'video_id' => $videoId,
                    'path' => $audioPath,
                ]);
            } catch (\Exception $e) {
                Log::warning("CleanupVideoProcessingJob: Failed to delete original audio", [
                    'video_id' => $videoId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete original video file from MinIO.
     * 
     * Note: Only call this if you're sure you don't need the video anymore.
     * By default, this is not called.
     */
    protected function deleteOriginalVideo(int $videoId): void
    {
        $this->video->refresh();

        if ($this->video->minio_path && Storage::disk('minio')->exists($this->video->minio_path)) {
            try {
                Storage::disk('minio')->delete($this->video->minio_path);

                Log::info("CleanupVideoProcessingJob: Deleted original video", [
                    'video_id' => $videoId,
                    'path' => $this->video->minio_path,
                ]);
            } catch (\Exception $e) {
                Log::warning("CleanupVideoProcessingJob: Failed to delete original video", [
                    'video_id' => $videoId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("CleanupVideoProcessingJob: Failed permanently for video_id={$this->video->id}", [
            'error' => $exception->getMessage(),
        ]);

        // Cleanup failure is not critical - video is already done, just temp files remain
        // Do NOT mark video as failed
    }
}
