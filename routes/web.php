<?php

use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Upload UI Routes
Route::group(['prefix' => 'upload', 'as' => 'upload.'], function () {
    Route::get('/', [UploadController::class, 'create'])->name('create');
    Route::post('/', [UploadController::class, 'store'])->name('store');
    Route::get('/{uploadId}', [UploadController::class, 'status'])->name('status');
    Route::get('/{uploadId}/status', [UploadController::class, 'checkStatus'])->name('check_status');
    Route::get('/{uploadId}/download', [UploadController::class, 'download'])->name('download');
});
