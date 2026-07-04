<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed Burnfront game: the exact puzzle played, the final board the
 * player submitted, and their full move-by-move action log (marks, undos,
 * resets, hint placements). Unlike DailyScore/EndlessScore, this is an
 * insert-only log — one row per completed game, not a running best — kept
 * purely for review/replay and never consulted for scoring or leaderboards.
 */
class GamePlay extends Model
{
    protected $table = 'burnfront_game_plays';

    protected $fillable = [
        'user_id', 'mode', 'difficulty', 'date',
        'rows', 'cols', 'breaks', 'spark', 'clues',
        'shaded_cells', 'moves', 'time_ms', 'hints_used',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clues' => 'array',
            'shaded_cells' => 'array',
            'moves' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
