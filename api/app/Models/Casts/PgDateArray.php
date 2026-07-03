<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * PostgreSQL date[] <-> list<string 'Y-m-d'>. Covers streaks.frozen_dates
 * (contracts/db-schema.sql); dates stay plain strings because all day math is
 * UTC calendar arithmetic (ADR-0002).
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PgDateArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '{}') {
            return [];
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$key} must surface as a Postgres array literal");
        }

        return explode(',', trim($value, '{}'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("{$key} must be set from a list of Y-m-d strings");
        }

        foreach ($value as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException("{$key} entries must be Y-m-d strings");
            }
        }

        return '{'.implode(',', $value).'}';
    }
}
