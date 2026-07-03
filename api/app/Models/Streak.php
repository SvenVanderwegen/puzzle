<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\PgDateArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily streak state, UTC day semantics (ADR-0002). WS-07 owns the update logic.
 *
 * @property string $user_id
 * @property int $current_len
 * @property int $best_len
 * @property Carbon|null $last_daily_date
 * @property Carbon|null $freeze_available_at
 * @property list<string> $frozen_dates
 * @property Carbon|null $updated_at
 */
class Streak extends Model
{
    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_len' => 'integer',
            'best_len' => 'integer',
            'last_daily_date' => 'date',
            'freeze_available_at' => 'date',
            'frozen_dates' => PgDateArray::class,
            'updated_at' => 'datetime',
        ];
    }
}
