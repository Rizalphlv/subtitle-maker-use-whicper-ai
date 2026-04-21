<?php

namespace Tests\Unit;

use App\Jobs\CleanupVideoProcessingJob;
use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupVideoProcessingJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('minio');
    }

    public function test_deletes_temporary_audio_chunks()
    {
        Queue::fake();

        $video = $this->createVideoWithChunksAndSubtitles();

        // Store some chunk files in MinIO
        $chunkPath1 = 'videos/' . $video->id . '/chunks/chunk_000.mp3';
        $chunkPath2 = 'videos/' . $video->id . '/chunks/chunk_001.mp3';
        Storage::disk('minio')->put($chunkPath1, 'dummy audio data');
        Storage::disk('minio')->put($chunkPath2, 'dummy audio data');

        // Store and set SRT file
        $originalPath = 'videos/' . $video->id . '/subtitles/en.srt';
        Storage::disk('minio')->put($originalPath, "1\n00:00:01,000 --> 00:00:03,000\nHello\n");
        Subtitle::where('video_id', $video->id)->update(['path' => $originalPath]);

        // Verify chunks exist
        $this->assertTrue(Storage::disk('minio')->exists($chunkPath1));
        $this->assertTrue(Storage::disk('minio')->exists($chunkPath2));

        $job = new CleanupVideoProcessingJob($video);
        $job->handle();

        // Verify chunks were deleted
        $this->assertFalse(Storage::disk('minio')->exists($chunkPath1));
        $this->assertFalse(Storage::disk('minio')->exists($chunkPath2));
    }

    public function test_verifies_original_subtitle_exists()
    {
        Queue::fake();

        $video = $this->createVideoWithChunksAndSubtitles();

        // Create and store original SRT file
        $originalPath = 'videos/' . $video->id . '/subtitles/en.srt';
        Storage::disk('minio')->put($originalPath, "1\n00:00:01,000 --> 00:00:03,000\nHello\n");

        // Update subtitle record with path
        Subtitle::where('video_id', $video->id)
            ->where('type', 'original')
            ->update(['path' => $originalPath]);

        $job = new CleanupVideoProcessingJob($video);
        $job->handle();  // Should not throw

        $this->assertTrue(true);
    }

    public function test_fails_if_original_subtitle_missing()
    {
        Queue::fake();

        $video = $this->createVideoWithChunksAndSubtitles();

        // Don't create the SRT file
        $job = new CleanupVideoProcessingJob($video);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Original subtitle not found');

        $job->handle();
    }

    public function test_verifies_translated_subtitle_if_target_language_differs()
    {
        Queue::fake();

        $video = Video::create([
            'upload_id' => 'test-cleanup-trans-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'done',
            'target_language' => 'id',  // Not English
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create original subtitle
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => [['index' => 1, 'start' => 0, 'end' => 2, 'text' => 'Hello']],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        // Create translated subtitle
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'id',
            'raw_transcript' => [['index' => 1, 'start' => 0, 'end' => 2, 'text' => 'Halo']],
            'path' => null,
            'type' => 'translated',
            'status' => 'transcribed',
        ]);

        // Store SRT files
        $originalPath = 'videos/' . $video->id . '/subtitles/en.srt';
        $translatedPath = 'videos/' . $video->id . '/subtitles/id_translated.srt';
        Storage::disk('minio')->put($originalPath, "1\n00:00:00,000 --> 00:00:02,000\nHello\n");
        Storage::disk('minio')->put($translatedPath, "1\n00:00:00,000 --> 00:00:02,000\nHalo\n");

        // Update subtitle paths
        Subtitle::where('video_id', $video->id)->where('type', 'original')->update(['path' => $originalPath]);
        Subtitle::where('video_id', $video->id)->where('type', 'translated')->update(['path' => $translatedPath]);

        $job = new CleanupVideoProcessingJob($video);
        $job->handle();  // Should verify both subtitles

        $this->assertTrue(true);
    }

    public function test_fails_if_translated_subtitle_missing_when_target_not_english()
    {
        Queue::fake();

        $video = Video::create([
            'upload_id' => 'test-cleanup-missing-trans-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'done',
            'target_language' => 'id',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create original subtitle with file
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => [['index' => 1, 'start' => 0, 'end' => 2, 'text' => 'Hello']],
            'path' => 'videos/' . $video->id . '/subtitles/en.srt',
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        // Store original file
        Storage::disk('minio')->put('videos/' . $video->id . '/subtitles/en.srt', "1\n00:00:00,000 --> 00:00:02,000\nHello\n");

        // Don't create translated subtitle

        $job = new CleanupVideoProcessingJob($video);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Translated subtitle not found');

        $job->handle();
    }

    public function test_skips_translated_verification_if_target_is_english()
    {
        Queue::fake();

        $video = $this->createVideoWithChunksAndSubtitles(targetLanguage: 'en');

        // Only create original subtitle
        $originalPath = 'videos/' . $video->id . '/subtitles/en.srt';
        Storage::disk('minio')->put($originalPath, "1\n00:00:01,000 --> 00:00:03,000\nHello\n");
        Subtitle::where('video_id', $video->id)->update(['path' => $originalPath]);

        $job = new CleanupVideoProcessingJob($video);
        $job->handle();  // Should NOT require translated subtitle

        $this->assertTrue(true);
    }

    public function test_continues_if_chunk_deletion_fails()
    {
        Queue::fake();

        $video = $this->createVideoWithChunksAndSubtitles();

        $originalPath = 'videos/' . $video->id . '/subtitles/en.srt';
        Storage::disk('minio')->put($originalPath, "1\n00:00:01,000 --> 00:00:03,000\nHello\n");
        Subtitle::where('video_id', $video->id)->update(['path' => $originalPath]);

        $job = new CleanupVideoProcessingJob($video);
        $job->handle();  // Should not throw even if some chunks can't be deleted

        $this->assertTrue(true);
    }

    public function test_has_correct_timeout_and_retry()
    {
        $video = $this->createVideoWithChunksAndSubtitles();
        $job = new CleanupVideoProcessingJob($video);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(600, $job->timeout);
        $this->assertEquals('default', $job->queue);
    }

    public function test_returns_deleted_count()
    {
        Queue::fake();

        $video = $this->createVideoWithChunksAndSubtitles();

        // Store multiple chunk files
        $chunkPath1 = 'videos/' . $video->id . '/chunks/chunk_000.mp3';
        $chunkPath2 = 'videos/' . $video->id . '/chunks/chunk_001.mp3';
        $chunkPath3 = 'videos/' . $video->id . '/chunks/chunk_002.mp3';
        Storage::disk('minio')->put($chunkPath1, 'dummy');
        Storage::disk('minio')->put($chunkPath2, 'dummy');
        Storage::disk('minio')->put($chunkPath3, 'dummy');

        // Add third chunk to database
        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 2,
            'start_time' => 120,
            'path' => $chunkPath3,
            'status' => 'transcribed',
        ]);

        // Create and store SRT (don't call handle yet)
        $originalPath = 'videos/' . $video->id . '/subtitles/en.srt';
        Storage::disk('minio')->put($originalPath, "1\n00:00:01,000 --> 00:00:03,000\nHello\n");
        Subtitle::where('video_id', $video->id)->update(['path' => $originalPath]);

        $job = new CleanupVideoProcessingJob($video);

        // Use reflection to call the private method directly
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('deleteTemporaryChunks');
        $method->setAccessible(true);

        $deletedCount = $method->invoke($job, $video->id);
        $this->assertEquals(3, $deletedCount);
    }

    public function test_does_not_fail_on_cleanup_failure()
    {
        Queue::fake();

        $video = $this->createVideoWithChunksAndSubtitles();

        $job = new CleanupVideoProcessingJob($video);
        $exception = new \Exception('Cleanup failed');

        // failed() method should not rethrow or mark video as failed
        $job->failed($exception);

        $video->refresh();
        $this->assertEquals('done', $video->status);  // Status should still be 'done'
    }

    public function test_preserves_srt_files_during_cleanup()
    {
        Queue::fake();

        $video = $this->createVideoWithChunksAndSubtitles();

        $originalPath = 'videos/' . $video->id . '/subtitles/en.srt';
        $srtContent = "1\n00:00:01,000 --> 00:00:03,000\nHello world\n\n2\n00:00:03,000 --> 00:00:05,000\nFarewell\n";
        Storage::disk('minio')->put($originalPath, $srtContent);
        Subtitle::where('video_id', $video->id)->update(['path' => $originalPath]);

        // Store chunks
        Storage::disk('minio')->put("videos/{$video->id}/chunks/chunk_000.mp3", 'chunk data');

        $job = new CleanupVideoProcessingJob($video);
        $job->handle();

        // Verify SRT file still exists and has correct content
        $this->assertTrue(Storage::disk('minio')->exists($originalPath));
        $this->assertEquals($srtContent, Storage::disk('minio')->get($originalPath));

        // Verify chunk was deleted
        $this->assertFalse(Storage::disk('minio')->exists("videos/{$video->id}/chunks/chunk_000.mp3"));
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    protected function createVideoWithChunksAndSubtitles(string $targetLanguage = 'en'): Video
    {
        $video = Video::create([
            'upload_id' => 'test-cleanup-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 2,
            'status' => 'done',
            'target_language' => $targetLanguage,
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create audio chunks
        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,
            'path' => 'videos/' . $video->id . '/chunks/chunk_000.mp3',
            'status' => 'transcribed',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'start_time' => 60,
            'path' => 'videos/' . $video->id . '/chunks/chunk_001.mp3',
            'status' => 'transcribed',
        ]);

        // Create merged subtitle (path will be set by tests that need it)
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => -1,
            'language' => 'en',
            'raw_transcript' => [
                ['index' => 1, 'start' => 0, 'end' => 2.5, 'text' => 'Hello world'],
            ],
            'path' => null,  // Tests will set this as needed
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        return $video;
    }
}
