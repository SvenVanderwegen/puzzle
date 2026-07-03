<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * First-party analytics rows (ADR-0008). user_id has no FK so rows survive
 * anonymization; aggregated-then-purged at 13 months (retention:purge-events).
 *
 * @property int $id
 * @property string $anon_id
 * @property string|null $user_id
 * @property string $name
 * @property array<string, mixed> $props
 * @property Carbon|null $created_at
 */
#[Fillable(['anon_id', 'user_id', 'name', 'props', 'created_at'])]
class AnalyticsEvent extends Model
{
    protected $table = 'events';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'props' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
