<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Glicko-2 board rating (RATING.md). Written only by the Ratings domain (arch-tested).
 *
 * @property string $puzzle_id
 * @property float $rating
 * @property float $rd
 * @property float $volatility
 * @property int $attempts
 * @property Carbon|null $updated_at
 */
class BoardRating extends Model
{
    protected $primaryKey = 'puzzle_id';

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
            'rating' => 'float',
            'rd' => 'float',
            'volatility' => 'float',
            'attempts' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
