<?php

namespace App\Services;

use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ChunkUploadService
 *
 * Responsibilities:
 *  1. Store each incoming chunk to temporary local disk (avoids memory overload via streaming).
 *  2. Track how many chunks have arrived for a given upload_id.
 *  3. When all chunks are present, stream-merge them in order into a single file
 *     and upload the result to MinIO.
 *  4. Clean up temp chunks after a successful merge.
 *
 * Storage layout (local temp disk):
 *   storage/app/private/chunks/{upload_id}/chunk_{index}
 *
 * MinIO layout (after merge):
 *   videos/{upload_id}/original.{ext}
 */
class ChunkUploadService
{
    /** Disk used for temporary chunk storage (local, never exposed publicly). */
    private const TEMP_DISK = 'local';

    /** Disk used for permanent asset storage (MinIO). */
    private const ASSET_DISK = 'minio';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Persist a single chunk and return the Video record.
     * Creates the Video row on the first chunk (chunk_index === 0).
     */
    public function storeChunk(
        string      $uploadId,
        int         $chunkIndex,
        int         $totalChunks,
        string      $targetLanguage,
        string      $originalName,
        UploadedFile $chunk,
    ): Video {
        // Idempotency: find or create the Video record.
        $video = Video::firstOrCreate(
            ['upload_id' => $uploadId],
            [
                'filename'        => $this->sanitizeFilename($originalName),
                'original_name'   => $originalName,
                'total_chunks'    => $totalChunks,
                'target_language' => $targetLanguage,
                'status'          => 'uploading',
            ]
        );

        // Guard: reject if this upload already finished or failed.
        if (! $video->isUploading()) {
            throw new RuntimeException("Upload {$uploadId} is not in 'uploading' state.");
        }

        // Guard: total_chunks must be consistent across all chunk requests.
        if ($video->total_chunks !== $totalChunks) {
            throw new RuntimeException("total_chunks mismatch for upload {$uploadId}.");
        }

        $chunkPath = $this->chunkPath($uploadId, $chunkIndex);

        // Only write if the chunk isn't already stored (idempotent retry safety).
        if (! Storage::disk(self::TEMP_DISK)->exists($chunkPath)) {
            // Stream directly from the temp file — no full read into memory.
            $stream = fopen($chunk->getRealPath(), 'rb');
            Storage::disk(self::TEMP_DISK)->writeStream($chunkPath, $stream);
            fclose($stream);
        }

        return $video;
    }

    /**
     * Return true when every expected chunk is present on disk.
     */
    public function allChunksReceived(Video $video): bool
    {
        for ($i = 0; $i < $video->total_chunks; $i++) {
            if (! Storage::disk(self::TEMP_DISK)->exists($this->chunkPath($video->upload_id, $i))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stream-merge all chunks in index order into a single file, upload to
     * MinIO, update the Video record, and delete temporary chunk files.
     *
     * Uses PHP streams so memory consumption is O(buffer) not O(file size).
     *
     * @return string  MinIO path of the merged video.
     */
    public function mergeChunks(Video $video): string
    {
        $uploadId  = $video->upload_id;
        $extension = pathinfo($video->original_name, PATHINFO_EXTENSION) ?: 'mp4';
        $minioPath = "videos/{$uploadId}/original.{$extension}";

        // Open a write stream directly into MinIO (S3 multipart under the hood).
        $writeStream = fopen('php://temp', 'r+b');

        try {
            for ($i = 0; $i < $video->total_chunks; $i++) {
                $chunkPath   = $this->chunkPath($uploadId, $i);
                $readStream  = Storage::disk(self::TEMP_DISK)->readStream($chunkPath);

                if ($readStream === false) {
                    throw new RuntimeException("Cannot open chunk {$i} for upload {$uploadId}.");
                }

                stream_copy_to_stream($readStream, $writeStream);
                fclose($readStream);
            }

            // Rewind and push the assembled stream to MinIO.
            rewind($writeStream);
            Storage::disk(self::ASSET_DISK)->writeStream($minioPath, $writeStream);

        } finally {
            if (is_resource($writeStream)) {
                fclose($writeStream);
            }
        }

        // Persist the MinIO path, advance status to 'uploaded', then immediately
        // mark as 'queued' and dispatch the processing job.
        $video->update([
            'minio_path' => $minioPath,
            'status'     => 'queued',
        ]);

        // Clean up temporary chunks.
        $this->deleteChunks($uploadId, $video->total_chunks);

        // Dispatch on the dedicated 'default' queue.
        ProcessVideoJob::dispatch($video)->onQueue('default');

        Log::info('ChunkUploadService: merge complete', [
            'upload_id'  => $uploadId,
            'minio_path' => $minioPath,
        ]);

        return $minioPath;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function chunkPath(string $uploadId, int $index): string
    {
        return "chunks/{$uploadId}/chunk_{$index}";
    }

    private function deleteChunks(string $uploadId, int $totalChunks): void
    {
        for ($i = 0; $i < $totalChunks; $i++) {
            Storage::disk(self::TEMP_DISK)->delete($this->chunkPath($uploadId, $i));
        }

        // Remove the now-empty directory.
        Storage::disk(self::TEMP_DISK)->deleteDirectory("chunks/{$uploadId}");
    }

    /**
     * Strip dangerous characters from the original filename.
     * Keeps only alphanumerics, dots, dashes, and underscores.
     */
    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^\w.\-]/', '_', basename($name));

        return $name ?: 'upload';
    }
}
