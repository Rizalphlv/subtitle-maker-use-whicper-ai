<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * WhisperService
 *
 * Wrapper around Groq's Whisper API for audio transcription.
 *
 * Responsibilities:
 *  1. Download audio chunk from MinIO
 *  2. Send to Whisper API
 *  3. Parse JSON response
 *  4. Extract segments with text and timing
 *
 * Expected Whisper API response:
 * {
 *   "text": "full transcription",
 *   "segments": [
 *     {
 *       "id": 0,
 *       "start": 0.0,
 *       "end": 2.5,
 *       "text": "Hello world"
 *     },
 *     ...
 *   ],
 *   "language": "en"
 * }
 */
class WhisperService
{
    private const ASSET_DISK = 'minio';
    private const TEMP_DISK = 'local';

    private string $apiKey;
    private string $apiEndpoint;
    private string $model;

    public function __construct()
    {
        $this->apiKey       = config('services.groq.api_key') ?? env('GROQ_API_KEY');
        $this->apiEndpoint  = config('services.groq.endpoint') ?? env('GROQ_ENDPOINT', 'https://api.groq.com/openai/v1');
        $this->model        = config('services.groq.model') ?? env('GROQ_WHISPER_MODEL', 'whisper-large-v3');

        if (!$this->apiKey) {
            throw new RuntimeException('GROQ_API_KEY not configured. Set GROQ_API_KEY environment variable.');
        }
    }

    /**
     * Transcribe an audio chunk from MinIO using Whisper API.
     *
     * @param string $audioMinioPath  MinIO path to audio chunk
     *
     * @return array  Segments array: [["start" => 0.0, "end" => 2.5, "text" => "..."], ...]
     *
     * @throws RuntimeException  If API call fails or response is invalid.
     */
    public function transcribe(string $audioMinioPath): array
    {
        Log::info('WhisperService: starting transcription (translate to English)', [
            'audio_path' => $audioMinioPath,
        ]);

        $tempPath = "temp/whisper/chunk_" . uniqid() . ".mp3";

        try {
            // 1. Download audio chunk to temp storage.
            $this->downloadAudioToTemp($audioMinioPath, $tempPath);

            // 2. Call Groq Whisper translation API (auto-detect source, output English).
            $response = $this->callWhisperApi($tempPath);

            // 3. Extract and validate segments.
            $segments = $this->extractSegments($response);

            Log::info('WhisperService: transcription complete', [
                'audio_path'   => $audioMinioPath,
                'segment_count' => count($segments),
            ]);

            return $segments;

        } catch (Throwable $e) {
            Log::error('WhisperService: transcription failed', [
                'audio_path' => $audioMinioPath,
                'error'      => $e->getMessage(),
            ]);

            throw $e;

        } finally {
            // Clean up temp file.
            try {
                Storage::disk(self::TEMP_DISK)->delete($tempPath);
            } catch (Throwable $e) {
                Log::warning('WhisperService: failed to cleanup temp file', [
                    'temp_path' => $tempPath,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function downloadAudioToTemp(string $minioPath, string $tempPath): void
    {
        try {
            $stream = Storage::disk(self::ASSET_DISK)->readStream($minioPath);

            if ($stream === false) {
                throw new RuntimeException("Cannot read audio from MinIO: {$minioPath}");
            }

            Storage::disk(self::TEMP_DISK)->writeStream($tempPath, $stream);
            fclose($stream);

        } catch (Throwable $e) {
            throw new RuntimeException("Failed to download audio from MinIO: {$e->getMessage()}");
        }
    }

    /**
     * Call Groq Whisper API with audio file.
     *
     * @return array  Parsed JSON response from Whisper API.
     *
     * @throws RuntimeException  If API call fails.
     */
    private function callWhisperApi(string $tempPath): array
    {
        try {
            $absolutePath = Storage::disk(self::TEMP_DISK)->path($tempPath);

            Log::debug('WhisperService: calling Groq Whisper translation API', [
                'endpoint' => $this->apiEndpoint,
                'model'    => $this->model,
            ]);

            // Use /audio/translations so output is always English
            // regardless of the source audio language.
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])
            ->timeout(600) // 10 minute timeout for large files
            ->asMultipart()
            ->attach('file', fopen($absolutePath, 'rb'), 'audio.mp3')
            ->post("{$this->apiEndpoint}/audio/translations", [
                'model'           => $this->model,
                'response_format' => 'verbose_json', // Includes detailed timing info
            ]);

            if (!$response->successful()) {
                $errorMsg = $response->json('error.message') ?? $response->body();
                throw new RuntimeException(
                    "Whisper API error ({$response->status()}): {$errorMsg}"
                );
            }

            return $response->json();

        } catch (Throwable $e) {
            throw new RuntimeException("Failed to call Whisper API: {$e->getMessage()}");
        }
    }

    /**
     * Extract segments from Whisper API response.
     *
     * Validates that the response contains the expected structure and converts
     * segment timing to the format expected by the rest of the system.
     *
     * @return array  Normalized segments: [["start" => float, "end" => float, "text" => string], ...]
     */
    private function extractSegments(array $response): array
    {
        if (!isset($response['segments']) || !is_array($response['segments'])) {
            throw new RuntimeException(
                "Invalid Whisper response: missing 'segments' array. Got: " . json_encode($response)
            );
        }

        $segments = [];

        foreach ($response['segments'] as $segment) {
            if (!isset($segment['start'], $segment['end'], $segment['text'])) {
                Log::warning('WhisperService: skipping segment with missing fields', [
                    'segment' => $segment,
                ]);
                continue;
            }

            $segments[] = [
                'start' => (float) $segment['start'],
                'end'   => (float) $segment['end'],
                'text'  => (string) $segment['text'],
            ];
        }

        // Allow empty segments (e.g., silent audio chunks at end of video with credits/silence)
        // The merge process will skip chunks with no segments
        if (empty($segments)) {
            Log::info('WhisperService: empty segments returned (likely silent/empty audio chunk)', [
                'response_keys' => array_keys($response),
            ]);
        }

        return $segments;
    }
}
