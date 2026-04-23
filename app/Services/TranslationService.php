<?php

namespace App\Services;

use App\Models\Subtitle;
use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TranslationService
{
    private const MIN_WORDS_FOR_SUBSTANTIVE_LINE = 4;

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

        Video::findOrFail($videoId);

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
            return Subtitle::updateOrCreate(
                [
                    'video_id' => $videoId,
                    'chunk_index' => -1,
                    'language' => $targetLanguage,
                    'type' => 'translated',
                ],
                [
                    'raw_transcript' => [],
                    'status' => 'transcribed',
                ]
            );
        }

        // ─────────────────────────────────────────────────────────────────────────
        // Translate each segment individually (preserve segmentation)
        // ─────────────────────────────────────────────────────────────────────────
        $translatedTextsByIndex = [];
        $pendingTranslations = [];

        foreach ($segments as $position => $segment) {
            $text = $segment['text'];

            if ($this->shouldSkipTranslation($text)) {
                $translatedTextsByIndex[$position] = $text;
                continue;
            }

            $pendingTranslations[$position] = $text;
        }

        foreach (array_chunk($pendingTranslations, $this->getTranslationBatchSize(), true) as $batch) {
            $batchTexts = array_values($batch);
            $translatedBatch = $this->translateBatch(
                $batchTexts,
                $sourceLanguage,
                $targetLanguage
            );

            if ($this->shouldFallbackToSingleTranslations($batchTexts, $translatedBatch)) {
                Log::warning('TranslationService: suspicious batch translation detected, falling back to per-segment translation', [
                    'batch_size' => count($batchTexts),
                    'source_language' => $sourceLanguage,
                    'target_language' => $targetLanguage,
                ]);

                $translatedBatch = [];
                foreach ($batchTexts as $text) {
                    $translatedBatch[] = $this->translateSegment(
                        $text,
                        $sourceLanguage,
                        $targetLanguage
                    );
                }
            }

            $batchPositions = array_keys($batch);
            foreach ($translatedBatch as $batchIndex => $translatedText) {
                $translatedTextsByIndex[$batchPositions[$batchIndex]] = $translatedText;
            }
        }

        ksort($translatedTextsByIndex);

        $translatedSegments = [];
        foreach ($segments as $position => $segment) {
            $translatedSegments[] = [
                'index' => $segment['index'],
                'start' => $segment['start'],
                'end' => $segment['end'],
                'text' => $translatedTextsByIndex[$position] ?? $segment['text'],
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
     * Translate a batch of subtitle texts using OpenAI API.
     *
     * @param array<int, string> $texts
     * @param string $sourceLanguage Source language code
     * @param string $targetLanguage Target language code
     * @return array<int, string>
     * @throws RuntimeException If translation fails
     */
    protected function translateBatch(
        array $texts,
        string $sourceLanguage,
        string $targetLanguage
    ): array {
        if ($texts === []) {
            return [];
        }

        $apiKey = config('services.openai.api_key');
        if (!$apiKey) {
            throw new RuntimeException(
                'OpenAI API key not configured. Set OPENAI_API_KEY in .env'
            );
        }

        $endpoint = config('services.openai.endpoint');
        $sourceLangName = self::LANGUAGE_NAMES[$sourceLanguage] ?? $sourceLanguage;
        $targetLangName = self::LANGUAGE_NAMES[$targetLanguage] ?? $targetLanguage;

        $systemPrompt = "You translate subtitle lines from {$sourceLangName} to {$targetLangName}. "
            . 'Translate each line independently and naturally. Keep the original meaning and tone. '
            . 'Do not summarize, do not invent, and do not replace full sentences with short interjections. '
            . 'Return strict JSON with the exact shape {"translations":[{"index":1,"text":"..."}]}. '
            . 'The number of items must match the input exactly.';

        $userPrompt = json_encode([
            'lines' => array_map(
                static fn (string $text, int $index) => [
                    'index' => $index + 1,
                    'text' => $text,
                ],
                array_values($texts),
                array_keys(array_values($texts))
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$endpoint}/chat/completions", [
                'model' => $this->getTranslationModel(),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
                'temperature' => 0.1,
                'max_tokens' => max(256, count($texts) * 80),
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

            return $this->parseBatchTranslations(
                $data['choices'][0]['message']['content'],
                count($texts)
            );
        } catch (\Exception $e) {
            Log::error('TranslationService: batch translation failed', [
                'batch_size' => count($texts),
                'sample_text' => substr((string) ($texts[0] ?? ''), 0, 100),
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Failed to translate batch: {$e->getMessage()}"
            );
        }
    }

    /**
     * @return array<int, string>
     */
    protected function parseBatchTranslations(string $content, int $expectedCount): array
    {
        $decoded = json_decode($this->stripCodeFences(trim($content)), true);

        if (!is_array($decoded) || !isset($decoded['translations']) || !is_array($decoded['translations'])) {
            throw new RuntimeException('Invalid OpenAI API response format');
        }

        $translations = [];
        foreach ($decoded['translations'] as $item) {
            if (is_array($item) && array_key_exists('text', $item)) {
                $translations[] = trim((string) $item['text']);
                continue;
            }

            if (is_string($item)) {
                $translations[] = trim($item);
                continue;
            }

            throw new RuntimeException('Invalid OpenAI API response format');
        }

        if (count($translations) !== $expectedCount) {
            throw new RuntimeException(
                "Invalid translation count returned: expected {$expectedCount}, got " . count($translations)
            );
        }

        return $translations;
    }

    protected function stripCodeFences(string $content): string
    {
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }

        return $content;
    }

    protected function translateSegment(
        string $text,
        string $sourceLanguage,
        string $targetLanguage
    ): string {
        $translations = $this->translateBatch([$text], $sourceLanguage, $targetLanguage);

        return $translations[0] ?? $text;
    }

    protected function shouldFallbackToSingleTranslations(array $sourceTexts, array $translatedTexts): bool
    {
        if (count($sourceTexts) !== count($translatedTexts)) {
            return true;
        }

        $normalizedOutputs = [];
        $suspiciousCount = 0;
        $fillerLikeCount = 0;
        $substantiveSourceCount = 0;

        foreach ($sourceTexts as $index => $sourceText) {
            $translatedText = trim((string) ($translatedTexts[$index] ?? ''));
            $normalizedOutputs[] = mb_strtolower($translatedText);

            if ($translatedText === '') {
                return true;
            }

             if ($this->countWords($sourceText) >= self::MIN_WORDS_FOR_SUBSTANTIVE_LINE) {
                $substantiveSourceCount++;
            }

            if ($this->isLikelyFiller($translatedText)) {
                $fillerLikeCount++;
            }

            if ($this->isSuspiciousTranslation($sourceText, $translatedText)) {
                $suspiciousCount++;
            }
        }

        $uniqueOutputs = array_unique($normalizedOutputs);
        if (count($normalizedOutputs) >= 3 && count($uniqueOutputs) <= 2 && $fillerLikeCount >= 2) {
            return true;
        }

        if ($substantiveSourceCount > 0 && $fillerLikeCount >= max(2, (int) ceil(count($translatedTexts) / 2))) {
            return true;
        }

        return $suspiciousCount >= 2;
    }

    protected function isSuspiciousTranslation(string $sourceText, string $translatedText): bool
    {
        $sourceWords = $this->countWords($sourceText);
        $translatedWords = $this->countWords($translatedText);

        if ($sourceWords >= self::MIN_WORDS_FOR_SUBSTANTIVE_LINE && $translatedWords <= 1) {
            return true;
        }

        if ($sourceWords >= self::MIN_WORDS_FOR_SUBSTANTIVE_LINE && $this->isLikelyFiller($translatedText)) {
            return true;
        }

        return false;
    }

    protected function countWords(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}\']+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    protected function isLikelyFiller(string $text): bool
    {
        $normalized = preg_replace('/[^\p{L}\s]/u', ' ', mb_strtolower(trim($text)));
        $normalized = preg_replace('/\s+/u', ' ', (string) $normalized);
        $normalized = trim((string) $normalized);

        if ($normalized === '') {
            return true;
        }

        return preg_match('/^(?:(?:ah+|uh+|hm+|hmm+|mm+|mmm+|oh+|aa+|oo+|eh+|ayo)(?:\s+|$))+$/u', $normalized) === 1;
    }

    protected function getTranslationModel(): string
    {
        return (string) config('services.openai.translation_model', 'gpt-4o-mini');
    }

    protected function getTranslationBatchSize(): int
    {
        return max(1, (int) config('services.openai.translation_batch_size', 1));
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

