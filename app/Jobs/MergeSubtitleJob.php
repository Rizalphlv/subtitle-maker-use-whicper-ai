<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\MergeSubtitleService;
use App\Services\SrtGeneratorService;
use App\Services\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MergeSubtitleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Video $video;

    public $tries = 3;
    public $timeout = 3600;  // 1 hour for large files

    public function __construct(Video $video)
    {
        $this->video = $video;
        $this->queue = 'default';
    }

    public function handle(
        MergeSubtitleService $mergeService,
        TranslationService $translationService,
        SrtGeneratorService $srtService,
    ): void {
        $videoId = $this->video->id;

        Log::info("MergeSubtitleJob: Starting for video_id={$videoId}");

        try {
            // Step 1: Merge all chunk subtitles into single original subtitle
            Log::info("MergeSubtitleJob: Merging subtitles for video_id={$videoId}");
            $mergeService->merge($videoId, 'en', 'original');
            Log::info("MergeSubtitleJob: Merge complete for video_id={$videoId}");

            // Step 2: Generate SRT for original language (English)
            Log::info("MergeSubtitleJob: Generating SRT for original (en) for video_id={$videoId}");
            $srtService->generate($videoId, 'en', 'original');
            Log::info("MergeSubtitleJob: SRT generated for original (en) for video_id={$videoId}");

            // Step 3: Translate if target language differs from English
            if ($this->video->target_language !== 'en') {
                Log::info("MergeSubtitleJob: Translating from en to {$this->video->target_language} for video_id={$videoId}");
                $translationService->translate($videoId, 'en', $this->video->target_language);
                Log::info("MergeSubtitleJob: Translation complete for video_id={$videoId}");

                // Step 4: Generate SRT for translated language
                Log::info("MergeSubtitleJob: Generating SRT for translated ({$this->video->target_language}) for video_id={$videoId}");
                $srtService->generate($videoId, $this->video->target_language, 'translated');
                Log::info("MergeSubtitleJob: SRT generated for translated ({$this->video->target_language}) for video_id={$videoId}");
            }

            // Step 5: Mark video as done
            $this->video->update(['status' => 'done']);
            Log::info("MergeSubtitleJob: Completed successfully for video_id={$videoId}. Status updated to 'done'");

            // Step 6: Dispatch cleanup job to delete temporary chunks
            Log::info("MergeSubtitleJob: Dispatching cleanup job for video_id={$videoId}");
            \App\Jobs\CleanupVideoProcessingJob::dispatch($this->video)->onQueue('default');
            Log::info("MergeSubtitleJob: Cleanup job dispatched for video_id={$videoId}");

        } catch (\Exception $exception) {
            Log::error("MergeSubtitleJob: Failed for video_id={$videoId}", [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            // Update video status to failed
            $this->video->update(['status' => 'failed']);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("MergeSubtitleJob: Failed permanently for video_id={$this->video->id}", [
            'error' => $exception->getMessage(),
        ]);

        // Status already set to 'failed' in handle()
    }
}
