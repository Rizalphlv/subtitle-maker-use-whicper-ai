<?php

use App\Http\Controllers\ChunkUploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:120,1')->group(function () {
    // Upload a single chunk.
    Route::post('/upload/chunk', [ChunkUploadController::class, 'store']);

    // Poll upload status by upload_id.
    Route::get('/upload/{uploadId}/status', [ChunkUploadController::class, 'status'])
         ->where('uploadId', '[0-9a-f\-]{36}');
});
