<?php

namespace App\Http\Controllers;

use App\Services\ChunkUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class ChunkUploadController extends Controller
{
    public function __construct(
        private readonly ChunkUploadService $uploadService,
    ) {}

    /**
     * POST /api/upload/chunk
     *
     * Accepts one chunk per request. When the final chunk is received,
     * triggers the merge automatically.
     *
     * Request fields:
     *   upload_id       string  UUID identifying the upload session (client-generated)
     *   chunk_index     int     0-based index of this chunk
     *   total_chunks    int     Total number of chunks in the upload
     *   target_language string  "en" or "id"
     *   chunk           file    The raw chunk binary
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id'       => ['required', 'string', 'uuid'],
            'chunk_index'     => ['required', 'integer', 'min:0'],
            'total_chunks'    => ['required', 'integer', 'min:1'],
            'target_language' => ['required', Rule::in(['en', 'id'])],
            'chunk'           => ['required', 'file', 'max:102400'], // 100 MB per chunk max
        ]);

        // Ensure chunk_index is within declared bounds.
        if ($validated['chunk_index'] >= $validated['total_chunks']) {
            return response()->json([
                'message' => 'chunk_index must be less than total_chunks.',
            ], 422);
        }

        try {
            $video = $this->uploadService->storeChunk(
                uploadId:       $validated['upload_id'],
                chunkIndex:     (int) $validated['chunk_index'],
                totalChunks:    (int) $validated['total_chunks'],
                targetLanguage: $validated['target_language'],
                originalName:   $request->file('chunk')->getClientOriginalName(),
                chunk:          $request->file('chunk'),
            );

            // Check if all chunks have now been received.
            if ($this->uploadService->allChunksReceived($video)) {
                $minioPath = $this->uploadService->mergeChunks($video);

                return response()->json([
                    'status'     => 'queued',
                    'upload_id'  => $video->upload_id,
                    'video_id'   => $video->id,
                    'minio_path' => $minioPath,
                    'message'    => 'All chunks merged. Processing job dispatched.',
                ]);
            }

            return response()->json([
                'status'      => 'uploading',
                'upload_id'   => $video->upload_id,
                'chunk_index' => $validated['chunk_index'],
                'message'     => 'Chunk stored successfully.',
            ]);

        } catch (Throwable $e) {
            Log::error('ChunkUploadController: failed to store chunk', [
                'upload_id'   => $validated['upload_id'],
                'chunk_index' => $validated['chunk_index'],
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to store chunk. Please retry this chunk.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/upload/{uploadId}/status
     *
     * Returns current upload status and how many chunks are on disk.
     */
    public function status(string $uploadId): JsonResponse
    {
        $video = \App\Models\Video::where('upload_id', $uploadId)->firstOrFail();

        return response()->json([
            'upload_id'       => $video->upload_id,
            'video_id'        => $video->id,
            'status'          => $video->status,
            'total_chunks'    => $video->total_chunks,
            'target_language' => $video->target_language,
            'minio_path'      => $video->minio_path,
        ]);
    }
}
