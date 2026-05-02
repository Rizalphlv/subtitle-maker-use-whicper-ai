<?php

namespace App\Jobs;

use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Services\WhisperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * TranscribeChunkJob
 *
 * Transcribe a single audio chunk using Whisper API.
 *
 * Responsibilities:
 *  1. Idempotency: check if chunk already transcribed before processing
 *  2. Call WhisperService to send chunk to Whisper API
 *  3. Store raw transcript (JSON segments) in Subtitle record
 *  4. Update AudioChunk status on completion
 *
 * Job placement: queued on the 'transcribe' queue for parallel processing.
 */
class TranscribeChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry this job up to 3 times on failure.
     * Individual chunks can fail without blocking others.
     */
    public int $tries = 3;

    /**
     * Allow 10 minutes per job (includes Whisper API call).
     */
    public int $timeout = 600;

    /**
     * Groq rate limit key — shared across all TranscribeChunkJob instances.
     * Capped at 18 requests per 60 seconds (Groq limit is 20/min).
     */
    private const RATE_LIMIT_KEY     = 'groq_whisper_requests';
    private const RATE_LIMIT_MAX     = 18;
    private const RATE_LIMIT_DECAY_S = 60;

    public function __construct(
        public readonly AudioChunk $audioChunk,
    ) {}

    public function handle(): void
    {
        try {
            Log::info('TranscribeChunkJob: starting', [
                'audio_chunk_id' => $this->audioChunk->id,
                'chunk_index'    => $this->audioChunk->chunk_index,
                'video_id'       => $this->audioChunk->video_id,
            ]);

            // Guard: if chunk already transcribed, skip (idempotency).
            if ($this->audioChunk->status === 'transcribed') {
                Log::info('TranscribeChunkJob: already transcribed, skipping', [
                    'audio_chunk_id' => $this->audioChunk->id,
                ]);
                return;
            }

            // Rate limit guard: max 18 requests per 60 seconds to stay within
            // Groq's 20 req/min free tier limit.
            if (!RateLimiter::attempt(
                self::RATE_LIMIT_KEY,
                self::RATE_LIMIT_MAX,
                fn () => true,
                self::RATE_LIMIT_DECAY_S
            )) {
                $retryAfter = RateLimiter::availableIn(self::RATE_LIMIT_KEY);
                Log::warning('TranscribeChunkJob: Groq rate limit reached, re-queuing', [
                    'audio_chunk_id' => $this->audioChunk->id,
                    'retry_after_s'  => $retryAfter,
                ]);
                $this->release($retryAfter + 1);
                return;
            }

            // Call Groq Whisper translation API — auto-detects source language,
            // always outputs English text.
            $video = $this->audioChunk->video;
            $sourceLanguage = $video->source_language ?? 'auto';

            $whisperService = new WhisperService();
            $segments = $whisperService->transcribe($this->audioChunk->path, $sourceLanguage);

            Log::info('TranscribeChunkJob: transcription received', [
                'audio_chunk_id' => $this->audioChunk->id,
                'segment_count'  => count($segments),
            ]);

            // Store raw transcript in Subtitle record.
            $subtitle = Subtitle::updateOrCreate(
                [
                    'video_id'   => $this->audioChunk->video_id,
                    'chunk_index' => $this->audioChunk->chunk_index,
                    'language'   => 'en',  // Always store transcriptions in English
                    'type'       => 'original',
                ],
                [
                    'raw_transcript' => $segments,
                    'status'         => 'transcribed',
                ]
            );

            Log::info('TranscribeChunkJob: subtitle record created', [
                'subtitle_id'    => $subtitle->id,
                'audio_chunk_id' => $this->audioChunk->id,
            ]);

            // Mark the audio chunk as transcribed.
            $this->audioChunk->markAsTranscribed();

            // Track usage in cache (reset daily at midnight)
            $cacheKey = 'whisper_daily_usage_' . date('Y-m-d');
            \Illuminate\Support\Facades\Cache::increment($cacheKey, 120);
            
            Log::info('TranscribeChunkJob: complete', [
                'audio_chunk_id' => $this->audioChunk->id,
                'chunk_index'    => $this->audioChunk->chunk_index,
            ]);

        } catch (Throwable $e) {
            Log::error('TranscribeChunkJob: exception in handle', [
                'audio_chunk_id' => $this->audioChunk->id,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('TranscribeChunkJob: failed after retries', [
            'audio_chunk_id' => $this->audioChunk->id,
            'chunk_index'    => $this->audioChunk->chunk_index,
            'error'          => $e->getMessage(),
        ]);

        $this->audioChunk->markAsFailed();

        // Also mark any subtitle record as failed.
        Subtitle::where('video_id', $this->audioChunk->video_id)
            ->where('chunk_index', $this->audioChunk->chunk_index)
            ->update(['status' => 'failed']);
    }
}
