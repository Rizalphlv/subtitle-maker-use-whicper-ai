<?php

namespace Tests\Unit;

use App\Models\Subtitle;
use App\Models\Video;
use App\Services\SrtGeneratorService;
use App\Services\SubtitleNormalizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SrtGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SrtGeneratorService $service;
    protected SubtitleNormalizerService $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new SubtitleNormalizerService();
        $this->service = new SrtGeneratorService($this->normalizer);

        // Mock MinIO storage
        Storage::fake('minio');
    }

    public function test_generate_creates_srt_with_correct_format()
    {
        $video = $this->createVideoWithSubtitle();

        $minioPath = $this->service->generate($video->id, 'en', 'original');

        // Verify SRT was stored
        $this->assertTrue(Storage::disk('minio')->exists($minioPath));

        // Verify content
        $content = Storage::disk('minio')->get($minioPath);

        // Check SRT format compliance
        $this->assertStringContainsString('1', $content);
        $this->assertStringContainsString('00:00:00,000 --> 00:00:02,500', $content);
        $this->assertStringContainsString('Hello world', $content);
        $this->assertStringContainsString('2', $content);
        $this->assertStringContainsString('00:00:02,500 --> 00:00:05,000', $content);
        $this->assertStringContainsString('This is a test', $content);
    }

    public function test_generate_creates_correct_srt_structure()
    {
        $video = $this->createVideoWithSubtitle();

        $minioPath = $this->service->generate($video->id, 'en', 'original');
        $content = Storage::disk('minio')->get($minioPath);

        // Parse the SRT to verify structure
        $lines = explode("\n", trim($content));

        // First subtitle
        $this->assertEquals('1', trim($lines[0]));  // Index
        $this->assertStringContainsString('-->', $lines[1]);  // Timestamp
        $this->assertEquals('Hello world', trim($lines[2]));  // Text
        $this->assertEquals('', trim($lines[3]));  // Blank line

        // Second subtitle
        $this->assertEquals('2', trim($lines[4]));  // Index
        $this->assertStringContainsString('-->', $lines[5]);  // Timestamp
        $this->assertEquals('This is a test', trim($lines[6]));  // Text
    }

    public function test_generate_stores_at_correct_minio_path()
    {
        $video = $this->createVideoWithSubtitle();

        $minioPath = $this->service->generate($video->id, 'en', 'original');

        // Verify path structure
        $this->assertStringContainsString("videos/{$video->id}/subtitles/en.srt", $minioPath);
    }

    public function test_generate_translated_stores_with_language_prefix()
    {
        $video = $this->createVideoWithTranslatedSubtitle();

        $minioPath = $this->service->generate($video->id, 'id', 'translated');

        // Verify path includes language and _translated suffix
        $this->assertStringContainsString("videos/{$video->id}/subtitles/id_translated.srt", $minioPath);
    }

    public function test_generate_updates_subtitle_path()
    {
        $video = $this->createVideoWithSubtitle();
        $subtitle = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('language', 'en')
            ->first();

        $this->assertNull($subtitle->path);  // Initially null

        $minioPath = $this->service->generate($video->id, 'en', 'original');

        // Verify path was updated
        $subtitle->refresh();
        $this->assertEquals($minioPath, $subtitle->path);
    }

    public function test_generate_timestamp_format_is_correct()
    {
        $video = $this->createVideoWithSubtitle();

        $minioPath = $this->service->generate($video->id, 'en', 'original');
        $content = Storage::disk('minio')->get($minioPath);

        // Verify timestamp format: HH:MM:SS,mmm --> HH:MM:SS,mmm
        $this->assertMatchesRegularExpression('/\d{2}:\d{2}:\d{2},\d{3}\s+-->\s+\d{2}:\d{2}:\d{2},\d{3}/', $content);
    }

    public function test_validate_srt_content_accepts_valid_srt()
    {
        $validSrt = "1\n00:00:00,000 --> 00:00:02,500\nHello world\n\n2\n00:00:02,500 --> 00:00:05,000\nSecond line\n";

        $this->assertTrue($this->service->validateSrtContent($validSrt));
    }

    public function test_validate_srt_content_rejects_invalid_format()
    {
        // Missing timestamp
        $invalidSrt = "1\nHello world\n\n";
        $this->assertFalse($this->service->validateSrtContent($invalidSrt));

        // Invalid timestamp format
        $invalidSrt = "1\n00:00:00 --> 00:00:02\nHello\n";
        $this->assertFalse($this->service->validateSrtContent($invalidSrt));
    }

    public function test_parse_srt_content_extracts_segments()
    {
        $srt = "1\n00:00:00,000 --> 00:00:02,500\nHello world\n\n2\n00:00:02,500 --> 00:00:05,000\nSecond segment\n";

        $segments = $this->service->parseSrtContent($srt);

        $this->assertCount(2, $segments);

        // First segment
        $this->assertEquals(1, $segments[0]['index']);
        $this->assertEquals(0.0, $segments[0]['start']);
        $this->assertEquals(2.5, $segments[0]['end']);
        $this->assertEquals('Hello world', $segments[0]['text']);

        // Second segment
        $this->assertEquals(2, $segments[1]['index']);
        $this->assertEquals(2.5, $segments[1]['start']);
        $this->assertEquals(5.0, $segments[1]['end']);
        $this->assertEquals('Second segment', $segments[1]['text']);
    }

    public function test_parse_srt_content_handles_multiline_text()
    {
        $srt = "1\n00:00:00,000 --> 00:00:02,500\nFirst line\nSecond line\n\n";

        $segments = $this->service->parseSrtContent($srt);

        $this->assertCount(1, $segments);
        $this->assertEquals("First line\nSecond line", $segments[0]['text']);
    }

    public function test_generate_fails_without_segments()
    {
        $video = Video::create([
            'upload_id' => 'test-no-segments-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create subtitle without segments
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => [],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No segments found');

        $this->service->generate($video->id, 'en', 'original');
    }

    public function test_generate_requires_merged_subtitle()
    {
        $video = Video::create([
            'upload_id' => 'test-no-merged-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->generate($video->id, 'en', 'original');
    }

    public function test_get_srt_content_retrieves_from_minio()
    {
        $video = $this->createVideoWithSubtitle();

        $minioPath = $this->service->generate($video->id, 'en', 'original');

        // Retrieve content
        $content = $this->service->getSrtContent($minioPath);

        $this->assertStringContainsString('Hello world', $content);
        $this->assertStringContainsString('This is a test', $content);
    }

    public function test_get_srt_content_fails_for_missing_file()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve SRT file');

        $this->service->getSrtContent('videos/999/subtitles/nonexistent.srt');
    }

    public function test_generate_preserves_segment_indices()
    {
        $video = $this->createVideoWithSubtitle();

        $minioPath = $this->service->generate($video->id, 'en', 'original');
        $content = Storage::disk('minio')->get($minioPath);

        $segments = $this->service->parseSrtContent($content);

        // Verify indices are sequential starting from 1
        foreach ($segments as $i => $segment) {
            $this->assertEquals($i + 1, $segment['index']);
        }
    }

    public function test_generate_handles_millisecond_precision()
    {
        $video = Video::create([
            'upload_id' => 'test-millis-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create subtitle with millisecond precision
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => [
                ['index' => 1, 'start' => 0.125, 'end' => 2.5, 'text' => 'Precise timing'],
                ['index' => 2, 'start' => 2.75, 'end' => 4.999, 'text' => 'More precision'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        $minioPath = $this->service->generate($video->id, 'en', 'original');
        $content = Storage::disk('minio')->get($minioPath);

        // Verify millisecond precision is preserved (within rounding tolerance)
        $this->assertStringContainsString('00:00:00,125', $content);
        $this->assertStringContainsString('00:00:02,750', $content);
        // Note: 4.999 may round to 00:00:04,998 or 00:00:04,999 depending on float precision
        $this->assertMatchesRegularExpression('/00:00:04,99[89]/', $content);
    }

    public function test_srt_file_ends_with_newline()
    {
        $video = $this->createVideoWithSubtitle();

        $minioPath = $this->service->generate($video->id, 'en', 'original');
        $content = Storage::disk('minio')->get($minioPath);

        // Verify file ends with newline
        $this->assertStringEndsWith("\n", $content);
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    protected function createVideoWithSubtitle(): Video
    {
        $video = Video::create([
            'upload_id' => 'test-srt-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => [
                ['index' => 1, 'start' => 0.0, 'end' => 2.5, 'text' => 'Hello world'],
                ['index' => 2, 'start' => 2.5, 'end' => 5.0, 'text' => 'This is a test'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        return $video;
    }

    protected function createVideoWithTranslatedSubtitle(): Video
    {
        $video = $this->createVideoWithSubtitle();

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'id',
            'raw_transcript' => [
                ['index' => 1, 'start' => 0.0, 'end' => 2.5, 'text' => 'Halo dunia'],
                ['index' => 2, 'start' => 2.5, 'end' => 5.0, 'text' => 'Ini adalah ujian'],
            ],
            'path' => null,
            'type' => 'translated',
            'status' => 'transcribed',
        ]);

        return $video;
    }
}
