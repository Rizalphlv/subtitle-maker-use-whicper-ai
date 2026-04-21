<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('upload_id')->unique()->comment('Client-generated UUID identifying the upload session');
            $table->string('filename');
            $table->string('original_name');
            $table->unsignedBigInteger('total_chunks');
            $table->enum('status', ['uploading', 'uploaded', 'queued', 'processing', 'done', 'failed'])
                  ->default('uploading');
            $table->enum('target_language', ['en', 'id'])->default('en');
            $table->string('minio_path')->nullable()->comment('Final merged video path in MinIO');
            $table->timestamps();

            $table->index(['upload_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
