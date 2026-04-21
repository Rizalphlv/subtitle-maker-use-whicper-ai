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
        $this->service = new TranslationService();
    }

    public function test_translate_english_to_indonesian()
    {
        // Mock OpenAI API responses for each segment
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => ['content' => 'Halo dunia'],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => ['content' => 'Ini adalah ujian'],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => ['content' => 'Subtitle yang diterjemahkan'],
                    ]],
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
        // Mock OpenAI responses
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'Teks terjemahan 1']]]])
                ->push(['choices' => [['message' => ['content' => 'Teks terjemahan 2']]]])
                ->push(['choices' => [['message' => ['content' => 'Teks terjemahan 3']]]])
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
        // Mock OpenAI responses
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'Segmen 1']]]])
                ->push(['choices' => [['message' => ['content' => 'Segmen 2']]]])
                ->push(['choices' => [['message' => ['content' => 'Segmen 3']]]])
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
                ->push(['choices' => [['message' => ['content' => 'Halo']]]])
                ->push(['choices' => [['message' => ['content' => 'Dunia']]]])
                ->push(['choices' => [['message' => ['content' => 'Lagi']]]])
                ->push(['choices' => [['message' => ['content' => 'Halo']]]])
                ->push(['choices' => [['message' => ['content' => 'Dunia']]]])
                ->push(['choices' => [['message' => ['content' => 'Lagi']]]])
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
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'Halo']]]])
                ->push(['choices' => [['message' => ['content' => 'Dunia']]]])
                ->push(['choices' => [['message' => ['content' => 'Lagi']]]])
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

    // ── Helper Methods ────────────────────────────────────────────────────────

    protected function createVideoWithMergedSubtitle(): Video
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
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => [
                ['index' => 1, 'start' => 0.0, 'end' => 2.5, 'text' => 'Hello world'],
                ['index' => 2, 'start' => 2.5, 'end' => 5.0, 'text' => 'This is a test'],
                ['index' => 3, 'start' => 5.0, 'end' => 7.5, 'text' => 'Translated subtitle'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        return $video;
    }
}
