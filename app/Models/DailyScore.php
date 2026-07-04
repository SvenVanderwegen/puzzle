<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's server-verified completion time for a given day's Burnfront
 * daily incident. Unique on (user_id, date): a player gets one recorded
 * attempt per day, enforced by the underlying table.
 *
 * hints_used is the number of /hint requests the server counted for this
 * account on this date (see BurnfrontController::bumpDailyHintCount()) — a
 * "clean case" (no hints) is 0. It's recorded once at submission time and
 * never updated afterward, same as time_ms.
 */
class DailyScore extends Model
{
    protected $table = 'burnfront_daily_scores';

    protected $fillable = ['user_id', 'date', 'time_ms', 'hints_used'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
