<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Anonymous percentile aggregates per daily (contracts/db-schema.sql).
 * Updated transactionally by the WS-07 solve path; read by GET /daily/{date}.
 *
 * @property string $date
 * @property int $solved_count
 * @property int $started_count
 * @property int|null $p50_ms
 * @property array<string, mixed>|null $histogram
 * @property Carbon|null $updated_at
 */
class DailyStat extends Model
{
    protected $table = 'daily_stats';

    protected $primaryKey = 'date';

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
            'solved_count' => 'integer',
            'started_count' => 'integer',
            'p50_ms' => 'integer',
            'histogram' => 'array',
            'updated_at' => 'datetime',
        ];
    }
}
