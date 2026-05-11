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

    private ?string $openaiApiKey;
    private string $openaiEndpoint;

    public function __construct()
    {
        $this->apiKey       = config('services.groq.api_key') ?? env('GROQ_API_KEY');
        $this->apiEndpoint  = config('services.groq.endpoint') ?? env('GROQ_ENDPOINT', 'https://api.groq.com/openai/v1');
        $this->model        = config('services.groq.model') ?? env('GROQ_WHISPER_MODEL', 'whisper-large-v3');

        $this->openaiApiKey = config('services.openai.api_key') ?? env('OPENAI_API_KEY');
        $this->openaiEndpoint = config('services.openai.endpoint') ?? env('OPENAI_ENDPOINT', 'https://api.openai.com/v1');

        if (!$this->apiKey) {
            throw new RuntimeException('GROQ_API_KEY not configured. Set GROQ_API_KEY environment variable.');
        }
    }

    /**
     * Transcribe an audio chunk from MinIO using Whisper API.
     *
     * @param string $audioMinioPath  MinIO path to audio chunk
     * @param string $sourceLanguage  Source language code (e.g., 'auto', 'ja', 'ko')
     *
     * @return array  Segments array: [["start" => 0.0, "end" => 2.5, "text" => "..."], ...]
     *
     * @throws RuntimeException  If API call fails or response is invalid.
     */
    public function transcribe(string $audioMinioPath, string $sourceLanguage = 'auto', ?string $initialPrompt = null): array
    {
        Log::info('WhisperService: starting transcription', [
            'audio_path' => $audioMinioPath,
            'source_language' => $sourceLanguage,
            'has_initial_prompt' => !empty($initialPrompt),
        ]);

        $tempPath = "temp/whisper/chunk_" . uniqid() . ".mp3";

        try {
            // 1. Download audio chunk to temp storage.
            $this->downloadAudioToTemp($audioMinioPath, $tempPath);

            // 2. Call FFmpeg to detect if the audio is actually silent/too quiet
            if ($this->isSilentAudio($tempPath)) {
                Log::info('WhisperService: audio is silent/quiet, skipping API call', [
                    'audio_path' => $audioMinioPath
                ]);
                return [['start' => 0.0, 'end' => 10.0, 'text' => '...']]; // Default placeholder
            }

            // 3. Call Groq Whisper transcription API.
            $response = $this->callWhisperApi($tempPath, $sourceLanguage, $initialPrompt);

            // 3. Extract and validate segments.
            $segments = $this->extractSegments($response);

            // 4. Translate segments via GPT-4o-mini API.
            $segments = $this->translateSegments($segments);

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
     * Use FFmpeg to detect if the audio chunk is silent or contains very low volume.
     * This helps avoid Whisper hallucinations on silent/background noise parts.
     */
    private function isSilentAudio(string $tempPath): bool
    {
        try {
            $absolutePath = Storage::disk(self::TEMP_DISK)->path($tempPath);
            
            // Run volumedetect filter
            $command = "ffmpeg -i " . escapeshellarg($absolutePath) . " -af volumedetect -f null - 2>&1";
            $output = shell_exec($command);

            if (preg_match('/max_volume: ([\-\d\.]+) dB/', $output, $matches)) {
                $maxVolume = (float) $matches[1];
                
                // Threshold: -45dB is usually background hiss or silence.
                // Normal speech is typically between -10dB and -25dB.
                Log::debug("WhisperService: Volume detection", ['max_volume' => $maxVolume]);
                
                return $maxVolume < -45.0; 
            }
        } catch (Throwable $e) {
            Log::warning("WhisperService: Silence detection failed, proceeding to API", ['error' => $e->getMessage()]);
        }

        return false; // Default to not silent if detection fails
    }

    /**
     * Call Groq Whisper API with audio file.
     *
     * @return array  Parsed JSON response from Whisper API.
     *
     * @throws RuntimeException  If API call fails.
     */
    private function callWhisperApi(string $tempPath, string $sourceLanguage = 'auto', ?string $initialPrompt = null): array
    {
        try {
            $absolutePath = Storage::disk(self::TEMP_DISK)->path($tempPath);

            Log::debug('WhisperService: calling Groq Whisper transcription API', [
                'endpoint' => $this->apiEndpoint,
                'model'    => $this->model,
                'has_initial_prompt' => !empty($initialPrompt),
            ]);

            // Use /audio/transcriptions instead of translations
            $payload = [
                'model'           => $this->model,
                'response_format' => 'verbose_json',
                'temperature'     => 0, // Minimize creativity/hallucination
            ];

            if ($sourceLanguage !== 'auto') {
                $payload['language'] = $sourceLanguage;
            }

            // Combine base prompt with context from previous chunk if available
            $basePrompt = "Transcribe verbatim. Use punctuation for pauses. Output only speech.";
            if ($initialPrompt) {
                // Whisper prompt limit is ~224 tokens. We'll take the last ~100 characters.
                $context = mb_substr($initialPrompt, -100);
                $payload['prompt'] = "{$basePrompt} Previous context: ... {$context}";
            } else {
                $payload['prompt'] = $basePrompt;
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])
                ->timeout(600) // 10 minute timeout for large files
                ->asMultipart()
                ->attach('file', fopen($absolutePath, 'rb'), 'audio.mp3')
                ->post("{$this->apiEndpoint}/audio/transcriptions", $payload);

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

    /**
     * Translate segments to English using GPT-4o-mini API.
     *
     * @param array $segments Original segments
     * @return array Translated segments
     */
    private function translateSegments(array $segments): array
    {
        Log::info('WhisperService: translating segments via GPT-4o-mini');

        foreach ($segments as $index => &$segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            // If it's just dots, dashes or empty, skip it immediately
            if ($text === '' || preg_match('/^[\.\-\s]+$/', $text)) {
                unset($segments[$index]);
                continue;
            }

            try {
                $translated = $this->gptTranslate($text);

                // Clean and filter
                $cleaned = preg_replace('/[^\x00-\x7F]+/', '', $translated);
                $filtered = $this->filterHallucinations($cleaned);

                // If filtered result is empty, it means it was a hallucination/junk
                // Instead of removing it, we change it to "..." as requested
                $segment['text'] = ($filtered === '') ? '...' : $filtered;
            } catch (Throwable $e) {
                Log::warning('WhisperService: GPT translation failed', [
                    'error' => $e->getMessage(),
                    'text' => mb_substr($text, 0, 30)
                ]);
            }
        }

        return $segments;
    }

    /**
     * Call OpenAI GPT-4o-mini to translate a single text to English.
     */
    private function gptTranslate(string $text): string
    {
        if (!$this->openaiApiKey) {
            Log::error('WhisperService: OPENAI_API_KEY not configured');
            return $text;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->openaiApiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->openaiEndpoint}/chat/completions", [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Act as a translation engine. Translate the input to natural English. make the sentences natural like humans without changing the meaning, make references to previous texts if there are any to strengthen the translation context per sentence 
RULES:
1. Output ONLY the translated text.
2. DO NOT include any conversational filler (e.g., "Sure", "Here is the translation", "I can help").
3. DO NOT include meta-talk about subscribing, donations, or credits.
4. If the input is just meta-talk or junk, return an empty string.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $text,
                    ],
                ],
                'temperature' => 0.1,
                'max_tokens' => 500,
            ]);

            if (!$response->successful()) {
                throw new RuntimeException("GPT translation failed with status {$response->status()}: " . $response->body());
            }

            $data = $response->json();
            return trim($data['choices'][0]['message']['content'] ?? $text);
        } catch (Throwable $e) {
            Log::warning("GPT translation error: " . $e->getMessage());
            return $text;
        }
    }

    /**
     * Filter out common Whisper/AI hallucinations and unwanted meta-text.
     */
    private function filterHallucinations(string $text): string
    {
        $trimmedText = trim($text);

        // 1. Self-Echo Detection: If the output matches or is very similar to our own prompt
        $prompts = [
            'transcribe verbatim',
            'use punctuation for pauses',
            'output only speech',
        ];
        
        foreach ($prompts as $p) {
            if (stripos($trimmedText, $p) !== false && mb_strlen($trimmedText) < (mb_strlen($p) + 10)) {
                return '';
            }
        }

        // 2. If the text is very short and contains only punctuation/junk
        if (mb_strlen($trimmedText) < 2 || preg_match('/^[\.\-\s\?\!]+$/', $trimmedText)) {
            return '';
        }

        $unwanted = [
            '/(do not )?use punctuation (only )?to (reflect|indicate) natural pauses/i',
            '/transcribe the audio verbatim/i',
            '/do not (omit any words|summarize|rephrase)/i',
            '/preserve the (original wording|consistent Java style|original words)/i',
            '/output only the transcription/i',
            '/please (feel free to )?like,? subscribe,? share/i',
            '/thank you (very much )?for (watching|your hard work|a CV)/i',
            '/thanks for watching/i',
            '/subtitles by/i',
            '/amara\.org/i',
            '/translated by/i',
            '/copyright/i',
            '/all rights reserved/i',
            '/hit the subscribe button/i',
            '/sure,? i can( do that)?/i',
            '/here is the translation/i',
            '/i would like to translate/i',
            '/identify patterns in the conversation/i',
            '/always remind yourself/i',
            '/gain experience through loop/i',
            '/avoid using past attachments/i',
            '/let the intention be fulfilled/i',
            '/hundreds of people are asking/i',
            '/ros smartwatch tool/i',
            '/disclose the captioning number/i',
            '/disinfection/i',
            '/m emission/i',
            '/resume or cancel/i',
            '/language deconstruction resonance/i',
            '/ffs boutique/i',
            '/repeat the pronunciation/i',
            '/stocks can be shared/i',
            '/you for watching/i',
            '/watching/i',
        ];

        // 2. Check if the text is ONLY one of the unwanted patterns
        foreach ($unwanted as $pattern) {
            if (preg_match($pattern, $text)) {
                $stripped = trim(preg_replace($pattern, '', $text));
                // If after removing the pattern, the remaining text is empty or very short, it was a hallucination
                if (mb_strlen($stripped) < 4) {
                    return '';
                }
            }
        }

        // 3. Clean any lingering unwanted patterns from within the text
        $cleaned = trim(preg_replace($unwanted, '', $text));

        // Final check: if we cleaned it so much it's basically empty
        if (mb_strlen($cleaned) < 2) {
            return '';
        }

        return $cleaned;
    }
}
