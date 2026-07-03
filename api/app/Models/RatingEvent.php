<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Rating audit trail for deterministic recompute; user_id is nullable so the trail
 * survives anonymization (contracts/db-schema.sql). Written only by the Ratings domain.
 *
 * @property int $id
 * @property int $solve_id
 * @property string|null $user_id
 * @property string|null $puzzle_id
 * @property float $score
 * @property float $weight
 * @property float $user_before
 * @property float $user_after
 * @property float $user_rd_before
 * @property float $user_rd_after
 * @property float $board_before
 * @property float $board_after
 * @property Carbon|null $created_at
 */
class RatingEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'weight' => 'float',
            'user_before' => 'float',
            'user_after' => 'float',
            'user_rd_before' => 'float',
            'user_rd_after' => 'float',
            'board_before' => 'float',
            'board_after' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
