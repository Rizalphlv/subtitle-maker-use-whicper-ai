<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->integer('chunk_index')->comment('Which audio chunk this transcription came from. Use -1 for merged records.');
            $table->enum('language', ['en', 'id'])->default('en')->comment('Language of transcription');
            $table->longText('raw_transcript')->nullable()->comment('Raw Whisper response (JSON segments with text & timing)');
            $table->string('path')->nullable()->comment('MinIO path to the final .srt file');
            $table->enum('type', ['original', 'translated'])->default('original');
            $table->enum('status', ['pending', 'transcribed', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['video_id', 'chunk_index']);
            $table->unique(['video_id', 'chunk_index', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitles');
    }
};
