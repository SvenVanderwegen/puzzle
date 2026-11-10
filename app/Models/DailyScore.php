<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's server-verified completion time for a given day's Burnfront
 * daily incident. Unique on (user_id, date): a player gets one recorded
 * attempt per day, enforced by the underlying table. `ranked` is false for
 * a guest run saved to the account on sign-in (see DailyRunStore): it
 * counts for the streak but not for the leaderboard.
 */
class DailyScore extends Model
{
    protected $table = 'burnfront_daily_scores';

    protected $fillable = ['user_id', 'date', 'time_ms', 'hints_used', 'ranked'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'ranked' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
