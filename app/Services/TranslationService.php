<?php

namespace App\Services;

use App\Models\Subtitle;
use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TranslationService
{
    /**
     * Language code mappings.
     */
    private const LANGUAGE_MAP = [
        'en' => 'en',
        'id' => 'id',
        'english' => 'en',
        'indonesian' => 'id',
    ];

    /**
     * Language display names for prompts.
     */
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'id' => 'Indonesian',
    ];

    /**
     * Translate merged subtitle to target language using OpenAI API.
     * 
     * OPTIMIZATION: Uses batch translation (single API request for all segments)
     * instead of per-segment translation to reduce API calls and cost.
     * 
     * Keeps timestamps unchanged, only translates text.
     * 
     * @param int $videoId Video ID
     * @param string $sourceLanguage Source language code (e.g., 'en')
     * @param string $targetLanguage Target language code (e.g., 'id')
     * @return Subtitle|null Translated subtitle record (or null if source == target)
     * @throws RuntimeException If translation fails
     */
    public function translate(
        int $videoId,
        string $sourceLanguage = 'en',
        string $targetLanguage = 'id'
    ): ?Subtitle {
        // Normalize language codes
        $sourceLanguage = $this->normalizeLanguage($sourceLanguage);
        $targetLanguage = $this->normalizeLanguage($targetLanguage);

        // If source and target are the same, skip translation
        if ($sourceLanguage === $targetLanguage) {
            Log::info('TranslationService: source and target languages match, skipping', [
                'video_id' => $videoId,
                'language' => $sourceLanguage,
            ]);
            return null;
        }

        $video = Video::findOrFail($videoId);

        Log::info('TranslationService: starting BATCH translation', [
            'video_id' => $videoId,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
        ]);

        // Get merged subtitle (chunk_index = -1)
        $mergedSubtitle = Subtitle::where('video_id', $videoId)
            ->where('chunk_index', -1)
            ->where('language', $sourceLanguage)
            ->where('type', 'original')
            ->firstOrFail();

        // Extract segments
        $segments = $mergedSubtitle->raw_transcript ?? [];

        if (empty($segments)) {
            throw new RuntimeException("No segments found to translate for video {$videoId}");
        }

        // ─────────────────────────────────────────────────────────────────────────
        // BATCH TRANSLATE all segments in single API request
        // ─────────────────────────────────────────────────────────────────────────
        Log::info('TranslationService: preparing batch translation', [
            'video_id' => $videoId,
            'segment_count' => count($segments),
            'from' => $sourceLanguage,
            'to' => $targetLanguage,
        ]);

        $translatedSegments = $this->batchTranslateSegments(
            $segments,
            $sourceLanguage,
            $targetLanguage
        );

        // ─────────────────────────────────────────────────────────────────────────
        // Store translated subtitle record
        // ─────────────────────────────────────────────────────────────────────────
        $translatedSubtitle = Subtitle::updateOrCreate(
            [
                'video_id' => $videoId,
                'chunk_index' => -1,
                'language' => $targetLanguage,
                'type' => 'translated',
            ],
            [
                'raw_transcript' => $translatedSegments,
                'status' => 'transcribed',
            ]
        );

        Log::info('TranslationService: batch translation complete', [
            'video_id' => $videoId,
            'subtitle_id' => $translatedSubtitle->id,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'segment_count' => count($translatedSegments),
        ]);

        return $translatedSubtitle;
    }

    /**
     * Translate a single segment using OpenAI API.
     * 
     * @param string $text Text to translate
     * @param string $sourceLanguage Source language code
     * @param string $targetLanguage Target language code
     * @return string Translated text
     * @throws RuntimeException If translation fails
     */
    /**
     * Normalize language code.
     * 
     * @param string $language Language code or name
     * @return string Normalized language code
     */
    protected function normalizeLanguage(string $language): string
    {
        $normalized = strtolower(trim($language));
        return self::LANGUAGE_MAP[$normalized] ?? $normalized;
    }

    /**
     * Check if translation is needed.
     * 
     * @param string $sourceLanguage Source language
     * @param string $targetLanguage Target language
     * @return bool
     */
    public function isTranslationNeeded(string $sourceLanguage, string $targetLanguage): bool
    {
        $source = $this->normalizeLanguage($sourceLanguage);
        $target = $this->normalizeLanguage($targetLanguage);
        return $source !== $target;
    }

    /**
     * Batch translate multiple segments in a single API request.
     * 
     * OPTIMIZATION: Combines all segments into one request to reduce API calls.
     * Format segments as numbered list, send to OpenAI, parse response.
     * 
     * @param array $segments Segments to translate
     * @param string $sourceLanguage Source language
     * @param string $targetLanguage Target language
     * @return array Translated segments with preserved timing and structure
     */
    protected function batchTranslateSegments(
        array $segments,
        string $sourceLanguage,
        string $targetLanguage
    ): array {
        // Build numbered list of texts to translate
        $textsToTranslate = [];
        foreach ($segments as $index => $segment) {
            $text = trim($segment['text']);
            
            // Skip empty/placeholder segments
            if ($this->shouldSkipTranslation($text)) {
                $textsToTranslate[$index] = $text; // Keep original
            } else {
                $textsToTranslate[$index] = $text;
            }
        }

        // Create prompt with all texts
        $sourceLangName = self::LANGUAGE_NAMES[$sourceLanguage] ?? $sourceLanguage;
        $targetLangName = self::LANGUAGE_NAMES[$targetLanguage] ?? $targetLanguage;

        $prompt = "You are a professional subtitle translator. Translate the following numbered subtitle lines from {$sourceLangName} to {$targetLangName}.\n\n";
        $prompt .= "IMPORTANT RULES:\n";
        $prompt .= "1. Keep the numbering exactly the same\n";
        $prompt .= "2. Do NOT merge or split lines\n";
        $prompt .= "3. Keep the order exactly as provided\n";
        $prompt .= "4. Return ONLY the translations, no explanations\n";
        $prompt .= "5. For empty lines or placeholders, return them as-is\n\n";
        $prompt .= "Lines to translate:\n";

        foreach ($textsToTranslate as $index => $text) {
            $prompt .= ($index + 1) . ". {$text}\n";
        }

        try {
            $apiKey = config('services.openai.api_key');
            if (!$apiKey) {
                throw new RuntimeException(
                    'OpenAI API key not configured. Set OPENAI_API_KEY in .env'
                );
            }

            $endpoint = config('services.openai.endpoint');

            Log::debug('TranslationService: sending batch translation request', [
                'segment_count' => count($textsToTranslate),
                'prompt_length' => strlen($prompt),
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$endpoint}/chat/completions", [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional subtitle translator. Translate subtitles accurately while preserving the original meaning, tone, and structure. Return ONLY the translated lines with no other text.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,  // Lower temperature for consistency
                'max_tokens' => 2048,
            ]);

            if (!$response->successful()) {
                $error = $response->json('error.message') ?? $response->body();
                throw new RuntimeException(
                    "OpenAI API error ({$response->status()}): {$error}"
                );
            }

            $data = $response->json();

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new RuntimeException('Invalid OpenAI API response format');
            }

            $translatedText = trim($data['choices'][0]['message']['content']);

            Log::debug('TranslationService: batch translation received', [
                'response_length' => strlen($translatedText),
            ]);

            // Parse response: split by newlines and extract translations
            $translatedLines = array_map('trim', explode("\n", $translatedText));

            // Build result segments with same structure as input
            $translatedSegments = [];
            $translatedLineIndex = 0;

            foreach ($segments as $index => $segment) {
                $translatedLine = $translatedLines[$translatedLineIndex] ?? '';

                // Remove numbering if present (e.g., "1. Translation" -> "Translation")
                if (preg_match('/^\d+\.\s+(.*)$/', $translatedLine, $matches)) {
                    $translatedLine = $matches[1];
                }

                // If no translation received or it's empty, use original
                if (empty($translatedLine)) {
                    $translatedLine = trim($segment['text']);
                }

                $translatedSegments[] = [
                    'index' => $segment['index'],
                    'start' => $segment['start'],
                    'end' => $segment['end'],
                    'text' => $translatedLine,
                ];

                $translatedLineIndex++;
            }

            Log::info('TranslationService: batch translation complete', [
                'input_count' => count($segments),
                'output_count' => count($translatedSegments),
            ]);

            return $translatedSegments;

        } catch (\Exception $e) {
            Log::error('TranslationService: batch translation failed', [
                'error' => $e->getMessage(),
                'segment_count' => count($segments),
            ]);

            throw new RuntimeException(
                "Failed to batch translate segments: {$e->getMessage()}"
            );
        }
    }

    /**
     * Check if segment should skip translation (empty, whitespace, or placeholder).
     * Saves API resources by not translating empty/placeholder text.
     * 
     * @param string $text Segment text
     * @return bool True if should skip translation
     */
    protected function shouldSkipTranslation(string $text): bool
    {
        $trimmed = trim($text);
        
        // Skip if empty or whitespace-only
        if (empty($trimmed)) {
            return true;
        }
        
        // Skip if only dots/ellipsis (common Whisper placeholder for silence)
        if (preg_match('/^\.+$/', $trimmed)) {
            return true;
        }
        
        return false;
    }

    /**
     * Get translated subtitle.
     * 
     * @param int $videoId Video ID
     * @param string $language Target language
     * @return Subtitle|null
     */
    public function getTranslated(int $videoId, string $language = 'id'): ?Subtitle
    {
        return Subtitle::where('video_id', $videoId)
            ->where('chunk_index', -1)
            ->where('language', $language)
            ->where('type', 'translated')
            ->first();
    }
}

