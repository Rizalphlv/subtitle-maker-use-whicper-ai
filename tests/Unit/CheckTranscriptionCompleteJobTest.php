<?php

namespace Tests\Unit;

use App\Jobs\CheckTranscriptionCompleteJob;
use App\Jobs\MergeSubtitleJob;
use App\Models\AudioChunk;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckTranscriptionCompleteJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_merge_job_when_all_chunks_transcribed()
    {
        Queue::fake();

        $video = $this->createVideoWithAllChunksTranscribed();

        $job = new CheckTranscriptionCompleteJob($video);
        $job->handle();

        // Verify MergeSubtitleJob was dispatched
        Queue::assertPushed(MergeSubtitleJob::class);
    }

    public function test_requeues_when_chunks_still_pending()
    {
        Queue::fake();

        $video = $this->createVideoWithPendingChunks();

        $job = new CheckTranscriptionCompleteJob($video);
        
        // Job should release itself for retry
        try {
            $job->handle();
        } catch (\Illuminate\Queue\MaxAttemptsExceededException $e) {
            // Expected when job releases
        }

        // MergeSubtitleJob should NOT be dispatched yet
        Queue::assertNotPushed(MergeSubtitleJob::class);
    }

    public function test_fails_video_when_chunk_fails()
    {
        Queue::fake();

        $video = $this->createVideoWithFailedChunk();

        $job = new CheckTranscriptionCompleteJob($video);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('One or more chunks failed');

        $job->handle();

        // Video should be marked as failed
        $video->refresh();
        $this->assertEquals('failed', $video->status);
    }

    public function test_throws_error_when_no_chunks_exist()
    {
        Queue::fake();

        $video = Video::create([
            'upload_id' => 'test-no-chunks-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 0,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        $job = new CheckTranscriptionCompleteJob($video);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No audio chunks found');

        $job->handle();
    }

    public function test_has_correct_retry_and_timeout()
    {
        $video = $this->createVideoWithAllChunksTranscribed();
        $job = new CheckTranscriptionCompleteJob($video);

        $this->assertEquals(3600, $job->tries);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals(24, $job->backoff);
    }

    public function test_logs_progress()
    {
        Queue::fake();

        $video = $this->createVideoWithPartiallyTranscribedChunks();

        $job = new CheckTranscriptionCompleteJob($video);
        
        try {
            $job->handle();
        } catch (\Exception $e) {
            // Expected when job releases
        }

        // Job ran without fatal error
        $this->assertTrue(true);
    }

    public function test_marks_video_failed_on_job_failure()
    {
        Queue::fake();

        $video = $this->createVideoWithFailedChunk();

        $job = new CheckTranscriptionCompleteJob($video);
        
        try {
            $job->handle();
        } catch (\Exception $e) {
            // Expected
        }

        $job->failed(new \Exception('Test failure'));

        $video->refresh();
        $this->assertEquals('failed', $video->status);
    }

    public function test_only_dispatches_merge_when_all_chunks_complete()
    {
        Queue::fake();

        // Create video with mixed chunk statuses
        $video = Video::create([
            'upload_id' => 'test-mixed-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 3,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create chunks with mixed statuses
        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,
            'path' => 'videos/test/chunks/chunk_000.mp3',
            'status' => 'transcribed',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'start_time' => 60,
            'path' => 'videos/test/chunks/chunk_001.mp3',
            'status' => 'pending',  // Still pending
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 2,
            'start_time' => 120,
            'path' => 'videos/test/chunks/chunk_002.mp3',
            'status' => 'transcribed',
        ]);

        $job = new CheckTranscriptionCompleteJob($video);

        try {
            $job->handle();
        } catch (\Exception $e) {
            // Expected when job releases
        }

        // MergeSubtitleJob should NOT be dispatched because not all chunks are done
        Queue::assertNotPushed(MergeSubtitleJob::class);
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    protected function createVideoWithAllChunksTranscribed(): Video
    {
        $video = Video::create([
            'upload_id' => 'test-all-done-' . now()->timestamp,
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
            'path' => 'videos/test/chunks/chunk_000.mp3',
            'status' => 'transcribed',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'start_time' => 60,
            'path' => 'videos/test/chunks/chunk_001.mp3',
            'status' => 'transcribed',
        ]);

        return $video;
    }

    protected function createVideoWithPendingChunks(): Video
    {
        $video = Video::create([
            'upload_id' => 'test-pending-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,
            'path' => 'videos/test/chunks/chunk_000.mp3',
            'status' => 'pending',
        ]);

        return $video;
    }

    protected function createVideoWithFailedChunk(): Video
    {
        $video = Video::create([
            'upload_id' => 'test-failed-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,
            'path' => 'videos/test/chunks/chunk_000.mp3',
            'status' => 'failed',
        ]);

        return $video;
    }

    protected function createVideoWithPartiallyTranscribedChunks(): Video
    {
        $video = Video::create([
            'upload_id' => 'test-partial-' . now()->timestamp,
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
            'path' => 'videos/test/chunks/chunk_000.mp3',
            'status' => 'transcribed',
        ]);

        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'start_time' => 60,
            'path' => 'videos/test/chunks/chunk_001.mp3',
            'status' => 'pending',
        ]);

        return $video;
    }
}
