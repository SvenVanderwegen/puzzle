<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DailyPuzzleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Daily Burn Order calendar, keyed by UTC date (ADR-0002). Dates are kept
 * as plain Y-m-d strings — the primary key is the date column itself.
 *
 * @property string $date
 * @property string $puzzle_id
 * @property int $incident_number
 * @property Carbon $published_at
 * @property string $calendar_version
 * @property bool $amnesty
 */
#[UseFactory(DailyPuzzleFactory::class)]
class DailyPuzzle extends Model
{
    /** @use HasFactory<DailyPuzzleFactory> */
    use HasFactory;

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
            'incident_number' => 'integer',
            'published_at' => 'datetime',
            'amnesty' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Puzzle, $this>
     */
    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }
}
