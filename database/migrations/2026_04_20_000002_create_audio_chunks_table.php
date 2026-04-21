<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index')->comment('0-based chunk index');
            $table->unsignedInteger('start_time')->comment('Start time in seconds');
            $table->string('path')->comment('MinIO path to the chunk file');
            $table->enum('status', ['pending', 'transcribed', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['video_id', 'chunk_index']);
            $table->unique(['video_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_chunks');
    }
};
