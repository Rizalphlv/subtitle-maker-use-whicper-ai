<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioChunk extends Model
{
    protected $fillable = [
        'video_id',
        'chunk_index',
        'start_time',
        'path',
        'status',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'start_time'  => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markAsTranscribed(): void
    {
        $this->update(['status' => 'transcribed']);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
}
