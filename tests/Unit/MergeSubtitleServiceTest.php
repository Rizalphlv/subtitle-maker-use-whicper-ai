<?php

namespace Tests\Unit;

use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use App\Services\MergeSubtitleService;
use App\Services\SubtitleNormalizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergeSubtitleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MergeSubtitleService $service;
    protected SubtitleNormalizerService $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new SubtitleNormalizerService();
        $this->service = new MergeSubtitleService($this->normalizer);
    }

    public function test_merge_creates_single_merged_record()
    {
        // Setup: Create video with 2 chunks
        $video = $this->createVideoWithChunks(2);

        // Merge
        $merged = $this->service->merge($video->id, 'en', 'original');

        // Assertions
        $this->assertNotNull($merged);
        $this->assertEquals($video->id, $merged->video_id);
        $this->assertEquals(-1, $merged->chunk_index);  // -1 = merged marker
        $this->assertEquals('en', $merged->language);
        $this->assertEquals('original', $merged->type);
        $this->assertEquals('transcribed', $merged->status);
    }

    public function test_merge_combines_all_segments_with_offsets()
    {
        // Setup: Create video with 2 chunks
        $video = $this->createVideoWithChunks(2);

        // Merge
        $merged = $this->service->merge($video->id, 'en', 'original');

        // Get segments
        $segments = $merged->raw_transcript;

        // Should have 4 segments total (2 from each chunk)
        $this->assertCount(4, $segments);

        // Verify chunk 0 segments (no offset)
        $this->assertEquals(1, $segments[0]['index']);
        $this->assertEquals(0.0, $segments[0]['start']);
        $this->assertEquals(2.5, $segments[0]['end']);
        $this->assertEquals('Hello world', $segments[0]['text']);

        // Verify chunk 1 segments (60-second offset)
        // Index 3: [60, 62.5] 'Hello world' (first segment of chunk 1)
        $this->assertEquals(3, $segments[2]['index']);
        $this->assertEquals(60.0, $segments[2]['start']);
        $this->assertEquals(62.5, $segments[2]['end']);
        $this->assertEquals('Hello world', $segments[2]['text']);

        // Index 4: [62.5, 65.0] 'This is chunk 1' (second segment of chunk 1)
        $this->assertEquals(4, $segments[3]['index']);
        $this->assertEquals(62.5, $segments[3]['start']);
        $this->assertEquals(65.0, $segments[3]['end']);
        $this->assertEquals('This is chunk 1', $segments[3]['text']);
    }

    public function test_merge_validates_timeline_and_logs_gaps()
    {
        // Setup: Create video with 2 chunks that have a gap
        $video = Video::create([
            'upload_id' => 'test-gap-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 2,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,
            'path' => 'videos/test/chunks/chunk_0.mp3',
            'status' => 'transcribed',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'start_time' => 70,  // Gap of 10 seconds after chunk 0 (5 seconds)
            'path' => 'videos/test/chunks/chunk_1.mp3',
            'status' => 'transcribed',
        ]);

        // Create subtitles with a gap
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'language' => 'en',
            'raw_transcript' => [
                ['start' => 0.0, 'end' => 5.0, 'text' => 'First segment'],
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
                ['start' => 0.0, 'end' => 2.0, 'text' => 'Second segment'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        // Merge (should log warning about gap but still succeed)
        $merged = $this->service->merge($video->id, 'en', 'original');

        // Should still create the merged record
        $this->assertNotNull($merged);
        $this->assertCount(2, $merged->raw_transcript);
        $this->assertEquals(5.0, $merged->raw_transcript[0]['end']);
        $this->assertEquals(70.0, $merged->raw_transcript[1]['start']);
    }

    public function test_merge_requires_all_chunks_transcribed()
    {
        // Setup: Create video with 2 chunks, but only 1 transcribed
        $video = Video::create([
            'upload_id' => 'test-incomplete-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 2,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,
            'path' => 'videos/test/chunks/chunk_0.mp3',
            'status' => 'transcribed',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'start_time' => 60,
            'path' => 'videos/test/chunks/chunk_1.mp3',
            'status' => 'pending',  // Not transcribed yet
        ]);

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'language' => 'en',
            'raw_transcript' => [
                ['start' => 0.0, 'end' => 2.0, 'text' => 'First chunk'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        // Should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not all chunks transcribed');

        $this->service->merge($video->id, 'en', 'original');
    }

    public function test_merge_allows_skipped_chunks_without_shifting_timeline()
    {
        $video = Video::create([
            'upload_id' => 'test-skipped-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 3,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        foreach ([0, 1, 2] as $index) {
            AudioChunk::create([
                'video_id' => $video->id,
                'chunk_index' => $index,
                'start_time' => $index * 60,
                'path' => "videos/test/chunks/chunk_{$index}.mp3",
                'status' => 'transcribed',
            ]);
        }

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'language' => 'en',
            'raw_transcript' => [
                ['start' => 0.0, 'end' => 2.0, 'text' => 'Opening line'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 2,
            'language' => 'en',
            'raw_transcript' => [
                ['start' => 0.0, 'end' => 3.0, 'text' => 'Closing line'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        $merged = $this->service->merge($video->id, 'en', 'original');

        $this->assertCount(2, $merged->raw_transcript);
        $this->assertEquals(0.0, $merged->raw_transcript[0]['start']);
        $this->assertEquals(120.0, $merged->raw_transcript[1]['start']);
        $this->assertEquals('Closing line', $merged->raw_transcript[1]['text']);
    }

    public function test_get_merged_retrieves_merged_record()
    {
        $video = $this->createVideoWithChunks(1);
        $this->service->merge($video->id, 'en', 'original');

        $merged = $this->service->getMerged($video->id, 'en', 'original');

        $this->assertNotNull($merged);
        $this->assertEquals(-1, $merged->chunk_index);
    }

    public function test_get_segment_count()
    {
        $video = $this->createVideoWithChunks(2);
        $this->service->merge($video->id, 'en', 'original');

        $count = $this->service->getSegmentCount($video->id, 'en');

        $this->assertEquals(4, $count);  // 2 chunks × 2 segments each
    }

    public function test_get_total_duration()
    {
        $video = $this->createVideoWithChunks(2);
        $this->service->merge($video->id, 'en', 'original');

        $duration = $this->service->getTotalDuration($video->id, 'en');

        // Last segment of chunk 1 ends at 5.0 + 60 = 65.0 seconds
        $this->assertEquals(65.0, $duration);
    }

    public function test_merge_is_idempotent()
    {
        $video = $this->createVideoWithChunks(1);

        // Merge twice
        $merged1 = $this->service->merge($video->id, 'en', 'original');
        $merged2 = $this->service->merge($video->id, 'en', 'original');

        // Should update the same record, not create a new one
        $this->assertEquals($merged1->id, $merged2->id);

        // Should have only one merged record
        $count = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->count();
        $this->assertEquals(1, $count);
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    protected function createVideoWithChunks(int $chunkCount): Video
    {
        $video = Video::create([
            'upload_id' => 'test-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => $chunkCount,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        for ($i = 0; $i < $chunkCount; $i++) {
            AudioChunk::create([
                'video_id' => $video->id,
                'chunk_index' => $i,
                'start_time' => $i * 60,
                'path' => "videos/test/chunks/chunk_{$i}.mp3",
                'status' => 'transcribed',
            ]);

            Subtitle::create([
                'video_id' => $video->id,
                'chunk_index' => $i,
                'language' => 'en',
                'raw_transcript' => [
                    ['start' => 0.0, 'end' => 2.5, 'text' => 'Hello world'],
                    ['start' => 2.5, 'end' => 5.0, 'text' => "This is chunk {$i}"],
                ],
                'path' => null,
                'type' => 'original',
                'status' => 'transcribed',
            ]);
        }

        return $video;
    }
}
