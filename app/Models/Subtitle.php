<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subtitle extends Model
{
    protected $fillable = [
        'video_id',
        'chunk_index',
        'language',
        'raw_transcript',
        'path',
        'type',
        'status',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'raw_transcript' => 'array', // Auto JSON encode/decode
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
