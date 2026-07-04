<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's running record for a named endless difficulty tier: how many
 * incidents they've closed at that tier and their fastest verified board.
 * Unique on (user_id, difficulty) — this is a running best, not a log of
 * every attempt. Scoped to the fixed tiers in PuzzleService::DIFFICULTIES
 * only; 'custom' grids vary too much per attempt to keep a meaningful best
 * against, so callers should never create a row for that difficulty.
 */
class EndlessScore extends Model
{
    protected $table = 'burnfront_endless_scores';

    protected $fillable = ['user_id', 'difficulty', 'solved_count', 'best_time_ms', 'last_solved_at'];

    protected function casts(): array
    {
        return [
            'last_solved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
