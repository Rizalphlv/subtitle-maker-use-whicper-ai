<?php

namespace App\Services;

use App\Models\AudioChunk;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AudioChunkService
 *
 * Responsibilities:
 *  1. Download audio from MinIO to temporary local storage.
 *  2. Execute FFmpeg to split audio into 60-second chunks.
 *  3. Upload each chunk to MinIO.
 *  4. Create AudioChunk records in database with metadata (chunk_index, start_time).
 *  5. Clean up temporary files.
 *
 * FFmpeg command:
 *   ffmpeg -i audio.mp3 -f segment -segment_time 60 -c copy chunk_%03d.mp3
 *
 * Storage layout (MinIO):
 *   videos/{upload_id}/audio.mp3              ← input
 *   videos/{upload_id}/chunks/chunk_000.mp3   ← chunk 0 (0-60s)
 *   videos/{upload_id}/chunks/chunk_001.mp3   ← chunk 1 (60-120s)
 *   ...
 */
class AudioChunkService
{
    private const ASSET_DISK = 'minio';
    private const TEMP_DISK = 'local';

    /** Segment duration in seconds (2 minutes). */
    private const SEGMENT_DURATION = 120;

    /**
     * Split audio into chunks and create database records.
     *
     * @return array<int, AudioChunk>  Created chunks, keyed by chunk_index.
     *
     * @throws RuntimeException  If FFmpeg fails or uploads fail.
     */
    public function split(Video $video, string $audioMinioPath): array
    {
        Log::info('AudioChunkService: starting split', [
            'video_id'          => $video->id,
            'audio_minio_path'  => $audioMinioPath,
        ]);

        $uploadId = $video->upload_id;
        $tempDir  = $this->getTempDir($uploadId);
        $inputPath  = "{$tempDir}/audio.mp3";
        $chunkDir   = "{$tempDir}/chunks";
        $chunks = [];

        try {
            // 1. Download audio from MinIO.
            $this->downloadAudioToTemp($audioMinioPath, $inputPath);

            // 2. Split audio into segments using FFmpeg.
            $chunkCount = $this->runFFmpegSegment($inputPath, $chunkDir);

            // 3. Upload chunks and create database records.
            $chunks = $this->uploadChunksAndCreateRecords($video, $chunkDir, $chunkCount);

            Log::info('AudioChunkService: split complete', [
                'video_id'    => $video->id,
                'chunk_count' => $chunkCount,
            ]);

            return $chunks;

        } catch (Throwable $e) {
            Log::error('AudioChunkService: split failed', [
                'video_id' => $video->id,
                'error'    => $e->getMessage(),
            ]);

            throw $e;

        } finally {
            // Always clean up temp files.
            $this->cleanupTempDir($tempDir);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getTempDir(string $uploadId): string
    {
        return "temp/audio_chunks/{$uploadId}";
    }

    /**
     * Download audio from MinIO to temporary local storage.
     */
    private function downloadAudioToTemp(string $minioPath, string $tempPath): void
    {
        try {
            $stream = Storage::disk(self::ASSET_DISK)->readStream($minioPath);

            if ($stream === false) {
                throw new RuntimeException("Cannot read audio from MinIO: {$minioPath}");
            }

            Storage::disk(self::TEMP_DISK)->writeStream($tempPath, $stream);
            fclose($stream);

        } catch (Throwable $e) {
            throw new RuntimeException("Failed to download audio from MinIO: {$e->getMessage()}");
        }
    }

    /**
     * Execute FFmpeg to segment audio into fixed-duration chunks.
     *
     * Command: ffmpeg -i audio.mp3 -f segment -segment_time 120 -c copy chunk_%03d.mp3
     *   -f segment          output format
     *   -segment_time 120   segment duration in seconds (2 minutes)
     *   -c copy             copy codec (no re-encoding, fast)
     *   chunk_%03d.mp3      output file pattern (chunk_000.mp3, chunk_001.mp3, ...)
     *
     * @return int  Total number of chunks created.
     */
    private function runFFmpegSegment(string $inputPath, string $chunkDir): int
    {
        $inputAbsPath = Storage::disk(self::TEMP_DISK)->path($inputPath);
        $chunkDirAbs  = Storage::disk(self::TEMP_DISK)->path($chunkDir);

        // Ensure chunk directory exists.
        @mkdir($chunkDirAbs, 0755, true);

        $outputPattern = "{$chunkDirAbs}/chunk_%03d.mp3";

        $command = [
            'ffmpeg',
            '-i', $inputAbsPath,
            '-f', 'segment',
            '-segment_time', (string) self::SEGMENT_DURATION,
            '-c', 'copy',
            '-y', // Overwrite without asking
            $outputPattern,
        ];

        Log::debug('AudioChunkService: executing FFmpeg segment', [
            'command' => implode(' ', $command),
        ]);

        $process = new Process($command);
        $process->setTimeout(3600);
        $process->setIdleTimeout(300);

        try {
            $process->mustRun();

        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                "FFmpeg segment failed: {$e->getMessage()}\n"
                . "STDERR: {$process->getErrorOutput()}"
            );
        }

        // Count created chunks by listing files in chunkDir.
        $chunkFiles = glob("{$chunkDirAbs}/chunk_*.mp3");
        $chunkCount = count($chunkFiles);

        if ($chunkCount === 0) {
            throw new RuntimeException("FFmpeg created no chunk files.");
        }

        Log::debug('AudioChunkService: FFmpeg segmentation complete', [
            'chunk_count' => $chunkCount,
        ]);

        return $chunkCount;
    }

    /**
     * Upload each chunk to MinIO and create AudioChunk records.
     *
     * Chunks are uploaded in index order (guaranteed by glob on chunk_%03d naming).
     *
     * @return array<int, AudioChunk>  Created chunks keyed by chunk_index.
     */
    private function uploadChunksAndCreateRecords(
        Video $video,
        string $chunkDir,
        int $chunkCount
    ): array {
        $uploadId = $video->upload_id;
        $chunks = [];
        $chunkDirAbs = Storage::disk(self::TEMP_DISK)->path($chunkDir);

        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkFile = sprintf("{$chunkDirAbs}/chunk_%03d.mp3", $i);

            if (!file_exists($chunkFile)) {
                throw new RuntimeException("Missing chunk file: chunk_{$i}");
            }

            $startTime = $i * self::SEGMENT_DURATION;
            $minioChunkPath = "videos/{$uploadId}/chunks/chunk_{$i}.mp3";

            // Upload chunk to MinIO.
            $stream = fopen($chunkFile, 'rb');
            Storage::disk(self::ASSET_DISK)->writeStream($minioChunkPath, $stream);
            fclose($stream);

            // Create AudioChunk record.
            $chunk = AudioChunk::create([
                'video_id'   => $video->id,
                'chunk_index' => $i,
                'start_time'  => $startTime,
                'path'        => $minioChunkPath,
                'status'      => 'pending',
            ]);

            $chunks[$i] = $chunk;

            Log::debug('AudioChunkService: chunk uploaded and recorded', [
                'chunk_index'   => $i,
                'start_time'    => $startTime,
                'minio_path'    => $minioChunkPath,
            ]);
        }

        return $chunks;
    }

    /**
     * Clean up all temporary files.
     */
    private function cleanupTempDir(string $tempDir): void
    {
        try {
            Storage::disk(self::TEMP_DISK)->deleteDirectory($tempDir);
        } catch (Throwable $e) {
            Log::warning('AudioChunkService: failed to cleanup temp dir', [
                'temp_dir' => $tempDir,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
