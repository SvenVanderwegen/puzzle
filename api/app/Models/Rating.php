<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Glicko-2 user rating (RATING.md). Written only by the Ratings domain (arch-tested).
 *
 * @property string $user_id
 * @property float $rating
 * @property float $rd
 * @property float $volatility
 * @property int $games
 * @property Carbon|null $updated_at
 */
class Rating extends Model
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
            'rating' => 'float',
            'rd' => 'float',
            'volatility' => 'float',
            'games' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
