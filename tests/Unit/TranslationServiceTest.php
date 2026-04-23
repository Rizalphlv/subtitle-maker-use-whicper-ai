<?php

namespace Tests\Unit;

use App\Models\Subtitle;
use App\Models\Video;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TranslationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.translation_batch_size' => 20]);
        $this->service = new TranslationService();
    }

    public function test_translate_english_to_indonesian()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => $this->fakeTranslationResponse([
                'Halo dunia',
                'Ini adalah ujian',
                'Subtitle yang diterjemahkan',
            ]),
        ]);

        // Setup: Create video with merged subtitle
        $video = $this->createVideoWithMergedSubtitle();

        // Translate EN -> ID
        $translated = $this->service->translate($video->id, 'en', 'id');

        // Assertions
        $this->assertNotNull($translated);
        $this->assertEquals($video->id, $translated->video_id);
        $this->assertEquals(-1, $translated->chunk_index);
        $this->assertEquals('id', $translated->language);
        $this->assertEquals('translated', $translated->type);
        $this->assertEquals('transcribed', $translated->status);

        // Check translated segments
        $segments = $translated->raw_transcript;
        $this->assertCount(3, $segments);
        $this->assertEquals('Halo dunia', $segments[0]['text']);
        $this->assertEquals('Ini adalah ujian', $segments[1]['text']);
        $this->assertEquals('Subtitle yang diterjemahkan', $segments[2]['text']);
        Http::assertSentCount(1);

        // Timestamps should be unchanged
        $this->assertEquals(0.0, $segments[0]['start']);
        $this->assertEquals(2.5, $segments[0]['end']);
    }

    public function test_translate_skips_when_source_equals_target()
    {
        $video = $this->createVideoWithMergedSubtitle();

        // Try to translate EN -> EN (should skip)
        $result = $this->service->translate($video->id, 'en', 'en');

        // Should return null
        $this->assertNull($result);

        // Should not create a translated record
        $translated = Subtitle::where('video_id', $video->id)
            ->where('language', 'en')
            ->where('type', 'translated')
            ->first();
        $this->assertNull($translated);
    }

    public function test_translate_preserves_timestamps()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => $this->fakeTranslationResponse([
                'Teks terjemahan 1',
                'Teks terjemahan 2',
                'Teks terjemahan 3',
            ]),
        ]);

        $video = $this->createVideoWithMergedSubtitle();
        $translated = $this->service->translate($video->id, 'en', 'id');

        $segments = $translated->raw_transcript;

        // Check all original timestamps are preserved
        $this->assertEquals(1, $segments[0]['index']);
        $this->assertEquals(0.0, $segments[0]['start']);
        $this->assertEquals(2.5, $segments[0]['end']);

        $this->assertEquals(2, $segments[1]['index']);
        $this->assertEquals(2.5, $segments[1]['start']);
        $this->assertEquals(5.0, $segments[1]['end']);

        $this->assertEquals(3, $segments[2]['index']);
        $this->assertEquals(5.0, $segments[2]['start']);
        $this->assertEquals(7.5, $segments[2]['end']);
    }

    public function test_translate_preserves_segmentation()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => $this->fakeTranslationResponse([
                'Segmen 1',
                'Segmen 2',
                'Segmen 3',
            ]),
        ]);

        $video = $this->createVideoWithMergedSubtitle();
        $translated = $this->service->translate($video->id, 'en', 'id');

        $originalSubtitle = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('language', 'en')
            ->first();
        $originalSegments = $originalSubtitle->raw_transcript;
        $translatedSegments = $translated->raw_transcript;

        // Should have same number of segments (no merging)
        $this->assertCount(count($originalSegments), $translatedSegments);

        // Should preserve all timestamps and indices
        foreach ($originalSegments as $i => $original) {
            $this->assertEquals($original['index'], $translatedSegments[$i]['index']);
            $this->assertEquals($original['start'], $translatedSegments[$i]['start']);
            $this->assertEquals($original['end'], $translatedSegments[$i]['end']);
        }
    }

    public function test_translate_is_idempotent()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->makeTranslationPayload(['Halo', 'Dunia', 'Lagi']))
                ->push($this->makeTranslationPayload(['Halo', 'Dunia', 'Lagi']))
        ]);

        $video = $this->createVideoWithMergedSubtitle();

        // Translate twice
        $translated1 = $this->service->translate($video->id, 'en', 'id');
        $translated2 = $this->service->translate($video->id, 'en', 'id');

        // Should update the same record
        $this->assertEquals($translated1->id, $translated2->id);

        // Should have only one translated record
        $count = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('language', 'id')
            ->where('type', 'translated')
            ->count();
        $this->assertEquals(1, $count);
        Http::assertSentCount(2);
    }

    public function test_translate_handles_api_error()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'Invalid API key']],
                401
            ),
        ]);

        $video = $this->createVideoWithMergedSubtitle();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API error');

        $this->service->translate($video->id, 'en', 'id');
    }

    public function test_translate_fails_without_api_key()
    {
        // Temporarily override config to have no API key
        config(['services.openai.api_key' => null]);

        $video = $this->createVideoWithMergedSubtitle();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key not configured');

        $this->service->translate($video->id, 'en', 'id');
    }

    public function test_translate_requires_merged_subtitle()
    {
        // Create video WITHOUT merged subtitle
        $video = Video::create([
            'upload_id' => 'test-no-merge-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'id',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->translate($video->id, 'en', 'id');
    }

    public function test_translate_allows_empty_merged_subtitle(): void
    {
        $video = Video::create([
            'upload_id' => 'test-empty-merge-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'id',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => [],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        $translated = $this->service->translate($video->id, 'en', 'id');

        $this->assertNotNull($translated);
        $this->assertSame([], $translated->raw_transcript);
        $this->assertEquals('id', $translated->language);
        $this->assertEquals('translated', $translated->type);
    }

    public function test_is_translation_needed()
    {
        $this->assertTrue($this->service->isTranslationNeeded('en', 'id'));
        $this->assertTrue($this->service->isTranslationNeeded('english', 'indonesian'));
        $this->assertFalse($this->service->isTranslationNeeded('en', 'en'));
        $this->assertFalse($this->service->isTranslationNeeded('id', 'id'));
    }

    public function test_get_translated_subtitle()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => $this->fakeTranslationResponse([
                'Halo',
                'Dunia',
                'Lagi',
            ]),
        ]);

        $video = $this->createVideoWithMergedSubtitle();
        $this->service->translate($video->id, 'en', 'id');

        $translated = $this->service->getTranslated($video->id, 'id');

        $this->assertNotNull($translated);
        $this->assertEquals('id', $translated->language);
        $this->assertEquals('translated', $translated->type);
    }

    public function test_translate_handles_invalid_api_response()
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([]),
        ]);

        $video = $this->createVideoWithMergedSubtitle();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid OpenAI API response format');

        $this->service->translate($video->id, 'en', 'id');
    }

    public function test_translate_batches_large_segment_list(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->makeTranslationPayload(array_map(
                    static fn (int $number) => "Terjemahan {$number}",
                    range(1, 20)
                )))
                ->push($this->makeTranslationPayload([
                    'Terjemahan 21',
                    'Terjemahan 22',
                    'Terjemahan 23',
                    'Terjemahan 24',
                    'Terjemahan 25',
                ])),
        ]);

        $video = $this->createVideoWithMergedSubtitle(25);

        $translated = $this->service->translate($video->id, 'en', 'id');

        $this->assertCount(25, $translated->raw_transcript);
        $this->assertEquals('Terjemahan 1', $translated->raw_transcript[0]['text']);
        $this->assertEquals('Terjemahan 25', $translated->raw_transcript[24]['text']);
        Http::assertSentCount(2);
    }

    public function test_translate_falls_back_when_batch_result_is_suspicious(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push($this->makeTranslationPayload([
                    'oh',
                    'oh',
                    'ayo',
                ]))
                ->push($this->makeTranslationPayload([
                    'Apakah ada yang merasa dunia ini penuh dengan orang baik?',
                ]))
                ->push($this->makeTranslationPayload([
                    'Atau sudah waktunya untuk direset?',
                ]))
                ->push($this->makeTranslationPayload([
                    'Baik semuanya, aku punya sesuatu untuk diumumkan.',
                ])),
        ]);

        $video = $this->createVideoWithMergedSubtitle();

        $translated = $this->service->translate($video->id, 'en', 'id');

        $this->assertEquals('Apakah ada yang merasa dunia ini penuh dengan orang baik?', $translated->raw_transcript[0]['text']);
        $this->assertEquals('Atau sudah waktunya untuk direset?', $translated->raw_transcript[1]['text']);
        $this->assertEquals('Baik semuanya, aku punya sesuatu untuk diumumkan.', $translated->raw_transcript[2]['text']);
        Http::assertSentCount(4);
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    protected function createVideoWithMergedSubtitle(int $segmentCount = 3): Video
    {
        $video = Video::create([
            'upload_id' => 'test-trans-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'id',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create merged subtitle (chunk_index = -1)
        $segments = [];
        for ($index = 1; $index <= $segmentCount; $index++) {
            $start = ($index - 1) * 2.5;
            $segments[] = [
                'index' => $index,
                'start' => $start,
                'end' => $start + 2.5,
                'text' => $segmentCount === 3
                    ? [
                        1 => 'Hello world',
                        2 => 'This is a test',
                        3 => 'Translated subtitle',
                    ][$index]
                    : "Segment {$index}",
            ];
        }

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => $segments,
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        return $video;
    }

    protected function fakeTranslationResponse(array $translations): \Closure
    {
        return fn () => Http::response($this->makeTranslationPayload($translations));
    }

    protected function makeTranslationPayload(array $translations): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'translations' => array_map(
                            static fn (string $text, int $index) => [
                                'index' => $index + 1,
                                'text' => $text,
                            ],
                            array_values($translations),
                            array_keys(array_values($translations))
                        ),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ]],
        ];
    }
}
