<?php

namespace Tests\Unit;

use App\Jobs\MergeSubtitleJob;
use App\Models\AudioChunk;
use App\Models\Subtitle;
use App\Models\Video;
use App\Services\MergeSubtitleService;
use App\Services\SrtGeneratorService;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MergeSubtitleJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('minio');
    }

    public function test_job_merges_transcribed_chunks()
    {
        Queue::fake();

        $video = $this->createVideoWithTranscribedChunks();

        $job = new MergeSubtitleJob($video);
        $job->handle(
            app(MergeSubtitleService::class),
            app(TranslationService::class),
            app(SrtGeneratorService::class),
        );

        // Verify merged subtitle was created
        $merged = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('type', 'original')
            ->first();

        $this->assertNotNull($merged);
        $this->assertCount(2, $merged->raw_transcript);
    }

    public function test_job_generates_srt_for_original()
    {
        Queue::fake();

        $video = $this->createVideoWithTranscribedChunks();

        $job = new MergeSubtitleJob($video);
        $job->handle(
            app(MergeSubtitleService::class),
            app(TranslationService::class),
            app(SrtGeneratorService::class),
        );

        // Verify SRT was generated and stored
        $merged = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('type', 'original')
            ->first();

        $this->assertNotNull($merged->path);
        $this->assertTrue(Storage::disk('minio')->exists($merged->path));
    }

    public function test_job_translates_if_target_language_differs()
    {
        Queue::fake();

        $video = $this->createVideoWithTranscribedChunks(targetLanguage: 'id');

        $job = new MergeSubtitleJob($video);
        $job->handle(
            app(MergeSubtitleService::class),
            app(TranslationService::class),
            app(SrtGeneratorService::class),
        );

        // Verify translated subtitle was created
        $translated = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('language', 'id')
            ->where('type', 'translated')
            ->first();

        $this->assertNotNull($translated);
    }

    public function test_job_skips_translation_if_target_is_english()
    {
        Queue::fake();

        $video = $this->createVideoWithTranscribedChunks(targetLanguage: 'en');

        $job = new MergeSubtitleJob($video);
        $job->handle(
            app(MergeSubtitleService::class),
            app(TranslationService::class),
            app(SrtGeneratorService::class),
        );

        // Verify translated subtitle was NOT created
        $translated = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('type', 'translated')
            ->first();

        $this->assertNull($translated);
    }

    public function test_job_generates_srt_for_translated()
    {
        Queue::fake();

        $video = $this->createVideoWithTranscribedChunks(targetLanguage: 'id');

        $job = new MergeSubtitleJob($video);
        $job->handle(
            app(MergeSubtitleService::class),
            app(TranslationService::class),
            app(SrtGeneratorService::class),
        );

        // Verify translated SRT was generated and stored
        $translated = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('language', 'id')
            ->where('type', 'translated')
            ->first();

        $this->assertNotNull($translated->path);
        $this->assertTrue(Storage::disk('minio')->exists($translated->path));
    }

    public function test_job_updates_video_status_to_done()
    {
        Queue::fake();

        $video = $this->createVideoWithTranscribedChunks();
        $this->assertEquals('processing', $video->status);

        $job = new MergeSubtitleJob($video);
        $job->handle(
            app(MergeSubtitleService::class),
            app(TranslationService::class),
            app(SrtGeneratorService::class),
        );

        $video->refresh();
        $this->assertEquals('done', $video->status);
    }

    public function test_job_sets_status_to_failed_on_error()
    {
        Queue::fake();

        $video = Video::create([
            'upload_id' => 'test-merge-error-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => 'en',
            'minio_path' => 'videos/test/original.mp4',
        ]);

        $job = new MergeSubtitleJob($video);

        try {
            $job->handle(
                app(MergeSubtitleService::class),
                app(TranslationService::class),
                app(SrtGeneratorService::class),
            );
        } catch (\Exception $e) {
            // Expected to fail due to missing subtitles
        }

        $video->refresh();
        $this->assertEquals('failed', $video->status);
    }

    public function test_job_has_correct_queue_and_retry()
    {
        $video = $this->createVideoWithTranscribedChunks();
        $job = new MergeSubtitleJob($video);

        $this->assertEquals('default', $job->queue);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(3600, $job->timeout);
    }

    public function test_job_handles_multiple_chunks()
    {
        Queue::fake();

        $video = $this->createVideoWithMultipleTranscribedChunks();

        $job = new MergeSubtitleJob($video);
        $job->handle(
            app(MergeSubtitleService::class),
            app(TranslationService::class),
            app(SrtGeneratorService::class),
        );

        // Verify merged subtitle contains all segments
        $merged = Subtitle::where('video_id', $video->id)
            ->where('chunk_index', -1)
            ->where('type', 'original')
            ->first();

        // Should have 4 segments (2 per chunk)
        $this->assertCount(4, $merged->raw_transcript);
        $this->assertEquals('done', $video->fresh()->status);
    }

    // ── Helper Methods ────────────────────────────────────────────────────────

    protected function createVideoWithTranscribedChunks(string $targetLanguage = 'en'): Video
    {
        $video = Video::create([
            'upload_id' => 'test-merge-job-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 1,
            'status' => 'processing',
            'target_language' => $targetLanguage,
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create audio chunk
        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,  // chunk_index * 60
            'path' => 'videos/test/chunks/chunk_000.mp3',
            'status' => 'transcribed',
        ]);

        // Create transcribed subtitle for chunk
        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'language' => 'en',
            'raw_transcript' => [
                ['index' => 1, 'start' => 0.0, 'end' => 2.5, 'text' => 'Chunk one segment one'],
                ['index' => 2, 'start' => 2.5, 'end' => 5.0, 'text' => 'Chunk one segment two'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        return $video;
    }

    protected function createVideoWithMultipleTranscribedChunks(string $targetLanguage = 'en'): Video
    {
        $video = Video::create([
            'upload_id' => 'test-merge-multi-' . now()->timestamp,
            'filename' => 'test.mp4',
            'original_name' => 'test.mp4',
            'total_chunks' => 2,
            'status' => 'processing',
            'target_language' => $targetLanguage,
            'minio_path' => 'videos/test/original.mp4',
        ]);

        // Create first chunk
        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'start_time' => 0,  // chunk_index * 60
            'path' => 'videos/test/chunks/chunk_000.mp3',
            'status' => 'transcribed',
        ]);

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 0,
            'language' => 'en',
            'raw_transcript' => [
                ['index' => 1, 'start' => 0.0, 'end' => 2.5, 'text' => 'Chunk zero segment one'],
                ['index' => 2, 'start' => 2.5, 'end' => 5.0, 'text' => 'Chunk zero segment two'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        // Create second chunk (offset by chunk_index * chunk_duration)
        AudioChunk::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'start_time' => 60,  // chunk_index * 60
            'path' => 'videos/test/chunks/chunk_001.mp3',
            'status' => 'transcribed',
        ]);

        Subtitle::create([
            'video_id' => $video->id,
            'chunk_index' => 1,
            'language' => 'en',
            'raw_transcript' => [
                ['index' => 1, 'start' => 0.0, 'end' => 2.5, 'text' => 'Chunk one segment one'],
                ['index' => 2, 'start' => 2.5, 'end' => 5.0, 'text' => 'Chunk one segment two'],
            ],
            'path' => null,
            'type' => 'original',
            'status' => 'transcribed',
        ]);

        return $video;
    }
}
