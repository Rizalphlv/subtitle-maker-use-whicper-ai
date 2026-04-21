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

            // Get the target language from the parent Video record.
            $video = $this->audioChunk->video;
            $targetLanguage = $video->target_language ?? 'en';

            // OPTIMIZATION: Direct English transcription
            // - If target = EN: use Whisper translate mode (auto-detect & translate to EN)
            // - If target = ID: transcribe in Indonesian
            if ($targetLanguage === 'en') {
                // Use Whisper translate mode: auto-detect language and translate to English
                $transcribeLanguage = null; // Will use auto-detection via omitting language param
                $useTranslate = true;
            } else {
                // For Indonesian target: transcribe in Indonesian language
                $transcribeLanguage = 'id'; // Explicitly set to Indonesian
                $useTranslate = false;
            }

            Log::info('TranscribeChunkJob: transcription mode', [
                'audio_chunk_id' => $this->audioChunk->id,
                'target_language' => $targetLanguage,
                'use_translate_mode' => $useTranslate,
            ]);

            // Call Whisper API to transcribe this chunk.
            $whisperService = new WhisperService();
            $segments = $whisperService->transcribe($this->audioChunk->path, $transcribeLanguage, $useTranslate);

            Log::info('TranscribeChunkJob: transcription received', [
                'audio_chunk_id' => $this->audioChunk->id,
                'segment_count'  => count($segments),
            ]);

            // Store raw transcript
            // - If useTranslate: already in English (from Whisper translate mode)
            // - If !useTranslate: in original language (Indonesian), will be translated later
            $subtitleLanguage = $useTranslate ? 'en' : 'id';
            
            $subtitle = Subtitle::updateOrCreate(
                [
                    'video_id'   => $this->audioChunk->video_id,
                    'chunk_index' => $this->audioChunk->chunk_index,
                    'language'   => $subtitleLanguage,
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
                'language'       => $subtitleLanguage,
            ]);

            // Mark the audio chunk as transcribed.
            $this->audioChunk->markAsTranscribed();

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
