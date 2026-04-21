<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $fillable = [
        'upload_id',
        'filename',
        'original_name',
        'total_chunks',
        'status',
        'target_language',
        'minio_path',
    ];

    protected $casts = [
        'total_chunks' => 'integer',
    ];

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isUploading(): bool
    {
        return $this->status === 'uploading';
    }

    public function isFullyUploaded(): bool
    {
        return $this->status === 'uploaded';
    }

    public function markAsUploaded(): void
    {
        $this->update(['status' => 'uploaded']);
    }

    public function markAsQueued(): void
    {
        $this->update(['status' => 'queued']);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
}
