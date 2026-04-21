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
     * Translates each segment individually to preserve segmentation.
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
            // \Log::info('TranslationService: source and target languages match, skipping', [
            //     'video_id' => $videoId,
            //     'language' => $sourceLanguage,
            // ]);
            return null;
        }

        $video = Video::findOrFail($videoId);

        // \Log::info('TranslationService: starting translation', [
        //     'video_id' => $videoId,
        //     'source_language' => $sourceLanguage,
        //     'target_language' => $targetLanguage,
        // ]);

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
        // Translate each segment individually (preserve segmentation)
        // ─────────────────────────────────────────────────────────────────────────
        $translatedSegments = [];
        foreach ($segments as $segment) {
            $text = $segment['text'];
            
            // Skip translation for empty or placeholder text to save API resources
            if ($this->shouldSkipTranslation($text)) {
                // \Log::debug('TranslationService: skipping translation for empty/placeholder text', [
                //     'text' => $text,
                //     'source_language' => $sourceLanguage,
                //     'target_language' => $targetLanguage,
                // ]);
                $translatedText = $text;
            } else {
                $translatedText = $this->translateSegment(
                    $text,
                    $sourceLanguage,
                    $targetLanguage
                );
            }

            $translatedSegments[] = [
                'index' => $segment['index'],
                'start' => $segment['start'],
                'end' => $segment['end'],
                'text' => $translatedText,
            ];
        }

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

        // \Log::info('TranslationService: translation complete', [
        //     'video_id' => $videoId,
        //     'subtitle_id' => $translatedSubtitle->id,
        //     'source_language' => $sourceLanguage,
        //     'target_language' => $targetLanguage,
        //     'segment_count' => count($translatedSegments),
        // ]);

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
    protected function translateSegment(
        string $text,
        string $sourceLanguage,
        string $targetLanguage
    ): string {
        $apiKey = config('services.openai.api_key');
        if (!$apiKey) {
            throw new RuntimeException(
                'OpenAI API key not configured. Set OPENAI_API_KEY in .env'
            );
        }

        $endpoint = config('services.openai.endpoint');
        $sourceLangName = self::LANGUAGE_NAMES[$sourceLanguage] ?? $sourceLanguage;
        $targetLangName = self::LANGUAGE_NAMES[$targetLanguage] ?? $targetLanguage;

        $prompt = "Translate the following subtitle text from {$sourceLangName} to {$targetLangName}. " .
                  "Provide ONLY the translation, with no explanations or additional text.\n\n" .
                  "Text: {$text}";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$endpoint}/chat/completions", [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional subtitle translator. Translate subtitles accurately while preserving the original meaning and tone.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,  // Lower temperature for consistency
                'max_tokens' => 256,
            ]);

            if (!$response->successful()) {
                $error = $response->json('error.message') ?? $response->body();
                throw new RuntimeException(
                    "OpenAI API error ({$response->status()}): {$error}"
                );
            }

            $data = $response->json();

            // Extract translated text from response
            if (!isset($data['choices'][0]['message']['content'])) {
                throw new RuntimeException('Invalid OpenAI API response format');
            }

            $translatedText = trim($data['choices'][0]['message']['content']);

            // \Log::debug('TranslationService: segment translated', [
            //     'source' => $text,
            //     'target' => $translatedText,
            //     'source_language' => $sourceLanguage,
            //     'target_language' => $targetLanguage,
            // ]);

            return $translatedText;
        } catch (\Exception $e) {
            Log::error('TranslationService: segment translation failed', [
                'text' => substr($text, 0, 100),
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Failed to translate segment: {$e->getMessage()}"
            );
        }
    }

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

