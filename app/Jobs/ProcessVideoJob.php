<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\AudioChunkService;
use App\Services\AudioExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ProcessVideoJob
 *
 * Entry point for all post-upload video processing.
 * Current responsibilities:
 *   1. Extract audio from video using FFmpeg
 *   2. Split audio into 60-second chunks
 *   3. Dispatch TranscribeChunkJob for each chunk (on 'transcribe' queue)
 *   4. Dispatch CheckTranscriptionCompleteJob to monitor completion and trigger merge
 *
 * Job Flow:
 *   ProcessVideoJob
 *   ├─ TranscribeChunkJob (dispatched for each chunk)
 *   └─ CheckTranscriptionCompleteJob (polls until all chunks done)
 *      └─ MergeSubtitleJob (auto-dispatched when all transcribed)
 *         ├─ MergeSubtitleService
 *         ├─ TranslationService (if needed)
 *         └─ SrtGeneratorService
 */
class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of times this job may be attempted before it is marked failed.
     * Individual chunk-level jobs have their own retry counts.
     */
    public int $tries = 3;

    /**
     * Seconds the job may run before it is forcibly killed.
     * Set high because FFmpeg/Whisper steps will eventually run here.
     */
    public int $timeout = 3600;

    public function __construct(public readonly Video $video) {}

    public function handle(): void
    {
        try {
            Log::info('ProcessVideoJob: starting', [
                'video_id'  => $this->video->id,
                'upload_id' => $this->video->upload_id,
            ]);

            // Guard: only proceed from 'queued' state (idempotency).
            if ($this->video->status !== 'queued') {
                Log::warning('ProcessVideoJob: skipped — unexpected status', [
                    'video_id' => $this->video->id,
                    'status'   => $this->video->status,
                ]);
                return;
            }

            // Advance status to 'processing'.
            $this->video->update(['status' => 'processing']);

            Log::info('ProcessVideoJob: status → processing', [
                'video_id'  => $this->video->id,
                'minio_path' => $this->video->minio_path,
            ]);

            // ── Step 1: Extract Audio ─────────────────────────────────────────────
            Log::info('ProcessVideoJob: extracting audio...');
            $audioExtractionService = new AudioExtractionService();
            $audioPath = $audioExtractionService->extract($this->video);

            Log::info('ProcessVideoJob: audio extracted', [
                'video_id'       => $this->video->id,
                'audio_minio_path' => $audioPath,
            ]);

            // ── Step 2: Split Audio into Chunks ────────────────────────────────────
            Log::info('ProcessVideoJob: splitting audio into chunks...');
            $audioChunkService = new AudioChunkService();
            $chunks = $audioChunkService->split($this->video, $audioPath);

            Log::info('ProcessVideoJob: audio split into chunks', [
                'video_id'  => $this->video->id,
                'chunk_count' => count($chunks),
            ]);

            // ── Step 3: Dispatch Transcription Jobs ─────────────────────────────────
            Log::info('ProcessVideoJob: dispatching transcription jobs...');
            foreach ($chunks as $chunk) {
                TranscribeChunkJob::dispatch($chunk)->onQueue('transcribe');
            }

            Log::info('ProcessVideoJob: transcription jobs dispatched', [
                'video_id' => $this->video->id,
                'job_count' => count($chunks),
            ]);

            // ── Step 4: Dispatch Transcription Completion Monitor ───────────────────
            // CheckTranscriptionCompleteJob polls until all chunks are transcribed,
            // then automatically dispatches MergeSubtitleJob.
            Log::info('ProcessVideoJob: dispatching transcription completion monitor...');
            CheckTranscriptionCompleteJob::dispatch($this->video)->onQueue('default');

            Log::info('ProcessVideoJob: completion monitor dispatched', [
                'video_id' => $this->video->id,
            ]);

        } catch (Throwable $e) {
            Log::error('ProcessVideoJob: exception in handle', [
                'video_id' => $this->video->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessVideoJob: failed', [
            'video_id' => $this->video->id,
            'error'    => $e->getMessage(),
        ]);

        $this->video->update(['status' => 'failed']);
    }
}
