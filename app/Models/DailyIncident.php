<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The persisted record of one day's generated Burnfront incident (grid,
 * spark, clues, name, blurb), keyed uniquely by date. generateDaily() is
 * deterministic for a given date, so this exists purely to make past
 * incidents cheap to re-display (case history/review) without re-running
 * the generator's uniqueness search — deriving the solution from a stored
 * incident is fast (Engine::deductionSolve), but regenerating one from
 * scratch is not.
 */
class DailyIncident extends Model
{
    protected $table = 'burnfront_daily_incidents';

    protected $fillable = ['date', 'rows', 'cols', 'breaks', 'spark', 'clues', 'name', 'blurb'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clues' => 'array',
        ];
    }
}
