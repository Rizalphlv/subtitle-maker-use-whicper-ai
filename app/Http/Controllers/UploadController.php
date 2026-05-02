<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Show the video upload form.
     */
    public function create()
    {
        $cacheKey = 'whisper_daily_usage_' . date('Y-m-d');
        $usageSeconds = \Illuminate\Support\Facades\Cache::get($cacheKey, 0);
        $dailyLimit = 28800;
        $percentage = min(100, max(0, ($usageSeconds / $dailyLimit) * 100));

        return view('upload.create', [
            'usageSeconds' => $usageSeconds,
            'dailyLimit' => $dailyLimit,
            'percentage' => $percentage,
        ]);
    }

    /**
     * Store the uploaded video and dispatch processing job.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'video' => 'required|file|mimes:mp4,avi,mov,mkv|max:5242880',  // 5GB max
            'target_language' => 'required|in:en,id',
            'source_language' => 'nullable|string|in:auto,ko,ja,th,zh',
            'filename' => 'nullable|string|max:255',
        ]);

        try {
            // Generate upload ID
            $uploadId = Str::uuid()->toString();

            // Store video file
            $file = $validated['video'];
            $originalName = $validated['filename'] ?? $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();

            $minioPath = "videos/{$uploadId}/original.{$extension}";

            Log::info('UploadController: Storing video file', [
                'upload_id' => $uploadId,
                'filename' => $originalName,
                'size' => $file->getSize(),
                'minio_path' => $minioPath,
            ]);

            // Store file in MinIO
            Storage::disk('minio')->putFileAs(
                "videos/{$uploadId}",
                $file,
                "original.{$extension}"
            );

            // Create Video record with status 'queued'
            $video = Video::create([
                'upload_id' => $uploadId,
                'filename' => $originalName,
                'original_name' => $originalName,
                'total_chunks' => 0,  // Will be set by ProcessVideoJob
                'status' => 'queued',  // Set to 'queued' for ProcessVideoJob
                'target_language' => $validated['target_language'],
                'source_language' => $validated['source_language'] ?? 'auto',
                'minio_path' => $minioPath,
            ]);

            Log::info('UploadController: Video record created', [
                'video_id' => $video->id,
                'upload_id' => $uploadId,
            ]);

            // Dispatch ProcessVideoJob
            ProcessVideoJob::dispatch($video)->onQueue('default');

            Log::info('UploadController: ProcessVideoJob dispatched', [
                'video_id' => $video->id,
            ]);

            if ($request->expectsJson()) {
                $request->session()->flash('success', 'Video uploaded successfully! Processing has started.');
                return response()->json([
                    'success' => true,
                    'redirect_url' => route('upload.status', ['uploadId' => $uploadId])
                ]);
            }

            // Redirect to status page
            return redirect()->route('upload.status', ['uploadId' => $uploadId])
                ->with('success', 'Video uploaded successfully! Processing has started.');

        } catch (\Exception $exception) {
            Log::error('UploadController: Upload failed', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload failed: ' . $exception->getMessage()
                ], 500);
            }

            return back()->withError('Upload failed: ' . $exception->getMessage());
        }
    }

    /**
     * Show processing status and download link.
     */
    public function status(Request $request)
    {
        $uploadId = $request->route('uploadId');

        $video = Video::where('upload_id', $uploadId)->firstOrFail();

        return view('upload.status', [
            'video' => $video,
            'uploadId' => $uploadId,
        ]);
    }

    /**
     * Get processing status via AJAX.
     */
    public function checkStatus(Request $request)
    {
        $uploadId = $request->route('uploadId');

        $video = Video::where('upload_id', $uploadId)->firstOrFail();

        $statusText = match ($video->status) {
            'uploaded' => 'Queued for processing...',
            'processing' => 'Processing video...',
            'done' => 'Complete! Ready for download.',
            'failed' => 'Processing failed. Please try again.',
            default => 'Unknown status',
        };

        return response()->json([
            'status' => $video->status,
            'statusText' => $statusText,
            'videoId' => $video->id,
        ]);
    }

    /**
     * Download the generated SRT subtitle file.
     */
    public function download(Request $request)
    {
        $uploadId = $request->route('uploadId');

        $video = Video::where('upload_id', $uploadId)->firstOrFail();

        if ($video->status !== 'done') {
            return back()->withError('Video is not ready for download yet.');
        }

        try {
            // Determine which SRT file to download (original or translated)
            $language = $request->query('lang', $video->target_language);
            $type = $language !== 'en' && $language !== $video->target_language ? 'translated' : 'original';

            // Construct MinIO path
            if ($type === 'translated') {
                $minioPath = "videos/{$video->id}/subtitles/{$language}_translated.srt";
            } else {
                $minioPath = "videos/{$video->id}/subtitles/{$language}.srt";
            }

            // Verify file exists
            if (!Storage::disk('minio')->exists($minioPath)) {
                return back()->withError('Subtitle file not found.');
            }

            // Get file content
            $content = Storage::disk('minio')->get($minioPath);

            // Determine filename
            $languageName = $language === 'id' ? 'Indonesian' : 'English';
            $typeLabel = $type === 'translated' ? '_translated' : '';
            $filename = pathinfo($video->filename, PATHINFO_FILENAME) . "_{$languageName}{$typeLabel}.srt";

            Log::info('UploadController: Subtitle downloaded', [
                'video_id' => $video->id,
                'language' => $language,
                'filename' => $filename,
            ]);

            // Return file download
            return response()->streamDownload(
                fn() => print($content),
                $filename,
                [
                    'Content-Type' => 'application/x-subrip',
                    'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                ]
            );

        } catch (\Exception $exception) {
            Log::error('UploadController: Download failed', [
                'video_id' => $video->id,
                'error' => $exception->getMessage(),
            ]);

            return back()->withError('Download failed: ' . $exception->getMessage());
        }
    }
}
