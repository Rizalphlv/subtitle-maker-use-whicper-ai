<?php

namespace Tests\Unit;

use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use App\Services\SubtitleNormalizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubtitleNormalizerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SubtitleNormalizerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SubtitleNormalizerService();
    }

    public function test_normalize_applies_timestamp_offset_correctly()
    {
        // Create a video
        $video = Video::create([
            'upload_id' => 'test-video-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 2,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create audio chunks
        $chunk0 = AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,    // First chunk starts at 0 seconds
            'path' => 'videos/test/chunks/chunk_0.mp3',
            'status' => 'transcribed',
        ]);

        $chunk1 = AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'start_time' => 60,   // Second chunk starts at 60 seconds (60 * chunk_index)
            'path' => 'videos/test/chunks/chunk_1.mp3',
            'status' => 'transcribed',
        ]);

        // Create subtitles with raw transcripts
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'language' => 'en',
            'raw_transcript' => [
                ['start' => 0.0, 'end' => 2.5, 'text' => 'Hello world'],
                ['start' => 2.5, 'end' => 5.0, 'text' => 'This is chunk 0'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'language' => 'en',
            'raw_transcript' => [
                ['start' => 0.0, 'end' => 2.0, 'text' => 'This is chunk 1'],
                ['start' => 2.0, 'end' => 4.5, 'text' => 'More content'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        // Normalize
        $normalized = $this->service->normalize($video->id, 'en');

        // Assertions
        $this->assertCount(4, $normalized);

        // Check chunk 0 segments (no offset)
        $this->assertEquals(0.0, $normalized[0]['start']);
        $this->assertEquals(2.5, $normalized[0]['end']);
        $this->assertEquals('Hello world', $normalized[0]['text']);

        // Check chunk 1 segments (offset = 60 seconds)
        $this->assertEquals(60.0, $normalized[2]['start']);
        $this->assertEquals(62.0, $normalized[2]['end']);
        $this->assertEquals('This is chunk 1', $normalized[2]['text']);

        $this->assertEquals(62.0, $normalized[3]['start']);
        $this->assertEquals(64.5, $normalized[3]['end']);
        $this->assertEquals('More content', $normalized[3]['text']);

        // Check re-indexing (1-indexed)
        $this->assertEquals(1, $normalized[0]['index']);
        $this->assertEquals(4, $normalized[3]['index']);
    }

    public function test_seconds_to_srt_time_format()
    {
        $this->assertEquals('00:00:00,000', $this->service->secondsToSrtTime(0));
        $this->assertEquals('00:00:01,000', $this->service->secondsToSrtTime(1));
        $this->assertEquals('00:00:05,500', $this->service->secondsToSrtTime(5.5));
        $this->assertEquals('00:01:05,500', $this->service->secondsToSrtTime(65.5));
        $this->assertEquals('01:00:00,000', $this->service->secondsToSrtTime(3600));
        $this->assertEquals('01:02:30,250', $this->service->secondsToSrtTime(3750.25));
    }

    public function test_is_valid_segment()
    {
        // Valid segment
        $this->assertTrue($this->service->isValidSegment([
            'start' => 0.0,
            'end' => 2.5,
            'text' => 'Hello',
        ]));

        // Invalid: start >= end
        $this->assertFalse($this->service->isValidSegment([
            'start' => 2.5,
            'end' => 0.0,
            'text' => 'Hello',
        ]));

        // Invalid: missing field
        $this->assertFalse($this->service->isValidSegment([
            'start' => 0.0,
            'end' => 2.5,
        ]));

        // Invalid: negative start
        $this->assertFalse($this->service->isValidSegment([
            'start' => -1.0,
            'end' => 2.5,
            'text' => 'Hello',
        ]));
    }
}
