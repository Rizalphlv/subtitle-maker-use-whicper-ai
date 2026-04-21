<?php

namespace App\Jobs;

use App\Models\AudioChunk;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckTranscriptionCompleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How many times to retry checking if all chunks are transcribed.
     * With delay, this allows ~24 hours of checking (3600 checks * 24 seconds).
     */
    public int $tries = 3600;

    /**
     * Timeout for this check job (should be quick, just DB queries).
     */
    public int $timeout = 300;

    /**
     * Delay between retries (24 seconds to allow transcription time).
     */
    public int $backoff = 24;

    public function __construct(public readonly Video $video) {}

    public function handle(): void
    {
        $videoId = $this->video->id;

        Log::info("CheckTranscriptionCompleteJob: Checking status for video_id={$videoId}");

        try {
            // Reload video to get fresh data
            $this->video->refresh();

            // Fetch all chunks for this video
            $chunks = AudioChunk::where('video_id', $videoId)->get();

            if ($chunks->isEmpty()) {
                Log::error("CheckTranscriptionCompleteJob: No chunks found for video_id={$videoId}");
                throw new \RuntimeException("No audio chunks found for video");
            }

            // Count transcribed chunks
            $transcribedCount = $chunks->where('status', 'transcribed')->count();
            $totalCount = $chunks->count();

            Log::info("CheckTranscriptionCompleteJob: Progress for video_id={$videoId}", [
                'transcribed' => $transcribedCount,
                'total' => $totalCount,
            ]);

            // If all chunks are transcribed, dispatch merge job and delete this job
            if ($transcribedCount === $totalCount) {
                Log::info("CheckTranscriptionCompleteJob: All chunks transcribed for video_id={$videoId}. Dispatching MergeSubtitleJob");

                MergeSubtitleJob::dispatch($this->video)->onQueue('default');

                // Job completes successfully
                return;
            }

            // Check if any chunks failed
            $failedCount = $chunks->where('status', 'failed')->count();
            if ($failedCount > 0) {
                Log::error("CheckTranscriptionCompleteJob: {$failedCount} chunks failed for video_id={$videoId}");
                $this->video->update(['status' => 'failed']);
                throw new \RuntimeException("One or more chunks failed to transcribe");
            }

            // Still waiting for chunks to complete - requeue with backoff
            Log::info("CheckTranscriptionCompleteJob: Waiting for remaining chunks for video_id={$videoId}. Requeuing...");
            $this->release($this->backoff);

        } catch (\Exception $exception) {
            Log::error("CheckTranscriptionCompleteJob: Error for video_id={$videoId}", [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("CheckTranscriptionCompleteJob: Failed permanently for video_id={$this->video->id}", [
            'error' => $exception->getMessage(),
        ]);

        // Update video status to failed
        $this->video->update(['status' => 'failed']);
    }
}
