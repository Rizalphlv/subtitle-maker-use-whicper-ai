<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AudioExtractionService
 *
 * Responsibilities:
 *  1. Download video from MinIO to a temporary local file.
 *  2. Execute FFmpeg to extract audio as MP3 (64kbps).
 *  3. Upload extracted audio to MinIO.
 *  4. Clean up temporary files.
 *  5. Return the MinIO path of the audio.
 *
 * FFmpeg command:
 *   ffmpeg -i input.mp4 -vn -acodec mp3 -ab 48k output.mp3
 *
 * Storage layout (MinIO):
 *   videos/{upload_id}/original.{ext}   ← input video
 *   videos/{upload_id}/audio.mp3         ← output audio
 */
class AudioExtractionService
{
    private const ASSET_DISK = 'minio';
    private const TEMP_DISK = 'local';

    /**
     * Extract audio from a video and store in MinIO.
     *
     * @return string  MinIO path of the extracted audio.
     *
     * @throws RuntimeException  If FFmpeg execution fails.
     */
    public function extract(Video $video): string
    {
        Log::info('AudioExtractionService: starting extraction', [
            'video_id'   => $video->id,
            'upload_id'  => $video->upload_id,
            'minio_path' => $video->minio_path,
        ]);

        $uploadId = $video->upload_id;
        $tempDir  = $this->getTempDir($uploadId);
        $inputPath  = "{$tempDir}/input_video";
        $outputPath = "{$tempDir}/audio.mp3";
        $minioAudioPath = "videos/{$uploadId}/audio.mp3";

        try {
            // 1. Download video from MinIO to temp storage.
            $this->downloadVideoToTemp($video->minio_path, $inputPath);

            // 2. Execute FFmpeg to extract audio.
            $this->runFFmpeg($inputPath, $outputPath);

            // 3. Upload audio to MinIO.
            $this->uploadAudioToMinio($outputPath, $minioAudioPath);

            Log::info('AudioExtractionService: extraction complete', [
                'video_id'        => $video->id,
                'minio_audio_path' => $minioAudioPath,
            ]);

            return $minioAudioPath;

        } catch (Throwable $e) {
            Log::error('AudioExtractionService: extraction failed', [
                'video_id'  => $video->id,
                'error'     => $e->getMessage(),
            ]);

            throw $e;

        } finally {
            // Always clean up temp files, even on failure.
            $this->cleanupTempDir($tempDir);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getTempDir(string $uploadId): string
    {
        return "temp/audio_extraction/{$uploadId}";
    }

    /**
     * Download video from MinIO to a temporary local file.
     */
    private function downloadVideoToTemp(string $minioPath, string $tempPath): void
    {
        try {
            // Read stream from MinIO and write to local temp storage.
            $stream = Storage::disk(self::ASSET_DISK)->readStream($minioPath);

            if ($stream === false) {
                throw new RuntimeException("Cannot read video from MinIO: {$minioPath}");
            }

            Storage::disk(self::TEMP_DISK)->writeStream($tempPath, $stream);
            fclose($stream);

        } catch (Throwable $e) {
            throw new RuntimeException("Failed to download video from MinIO: {$e->getMessage()}");
        }
    }

    /**
     * Execute FFmpeg to extract audio from video.
     *
     * Command: ffmpeg -i input.mp4 -vn -acodec mp3 -ab 48k output.mp3
     *   -i        input file
     *   -vn       no video output
     *   -acodec   audio codec (mp3)
     *   -ab       audio bitrate (48 kbps — optimized for speech, smaller file, faster processing)
     */
    private function runFFmpeg(string $inputPath, string $outputPath): void
    {
        // Resolve absolute paths in the container's filesystem.
        $inputAbsPath  = Storage::disk(self::TEMP_DISK)->path($inputPath);
        $outputAbsPath = Storage::disk(self::TEMP_DISK)->path($outputPath);

        // Build the FFmpeg command.
        $command = [
            'ffmpeg',
            '-i', $inputAbsPath,
            '-vn',
            '-acodec', 'mp3',
            '-ab', '60k',  // Increased from 48k for better audio quality & accuracy
            '-y', // Overwrite output file without asking
            $outputAbsPath,
        ];

        Log::debug('AudioExtractionService: executing FFmpeg', [
            'command' => implode(' ', $command),
        ]);

        $process = new Process($command);
        $process->setTimeout(3600); // 1 hour timeout
        $process->setIdleTimeout(300); // 5 minutes idle timeout

        try {
            $process->mustRun();

        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                "FFmpeg failed: {$e->getMessage()}\n"
                . "STDOUT: {$process->getOutput()}\n"
                . "STDERR: {$process->getErrorOutput()}"
            );
        }

        Log::debug('AudioExtractionService: FFmpeg completed successfully');
    }

    /**
     * Upload extracted audio to MinIO.
     */
    private function uploadAudioToMinio(string $tempAudioPath, string $minioAudioPath): void
    {
        try {
            $stream = Storage::disk(self::TEMP_DISK)->readStream($tempAudioPath);

            if ($stream === false) {
                throw new RuntimeException("Cannot read audio from temp storage: {$tempAudioPath}");
            }

            Storage::disk(self::ASSET_DISK)->writeStream($minioAudioPath, $stream);
            fclose($stream);

        } catch (Throwable $e) {
            throw new RuntimeException("Failed to upload audio to MinIO: {$e->getMessage()}");
        }
    }

    /**
     * Clean up all temporary files.
     */
    private function cleanupTempDir(string $tempDir): void
    {
        try {
            Storage::disk(self::TEMP_DISK)->deleteDirectory($tempDir);
        } catch (Throwable $e) {
            Log::warning('AudioExtractionService: failed to cleanup temp dir', [
                'temp_dir' => $tempDir,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
