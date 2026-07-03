<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The rating tables store float32 (PG `real`), so the live update chain is
 * f32-quantized between games: each listener writes state and the next one
 * reads it back as Postgres' shortest round-trip text. A deterministic replay
 * (ratings:recompute) must round identically to reproduce the live chain
 * bit-for-bit — and PHP has no float32 — so quantization goes through the
 * same channel a stored-row read uses: a `?::float4` round-trip on the same
 * connection.
 */
final class Float4
{
    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function quantize(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $selects = [];

        foreach (array_keys($values) as $index) {
            $selects[] = sprintf('?::float4 as q%d', $index);
        }

        $row = DB::selectOne('select '.implode(', ', $selects), $values);

        if (! is_object($row)) {
            throw new RuntimeException('float4 round-trip returned no row.');
        }

        $quantized = [];

        foreach ((array) $row as $value) {
            if (! is_numeric($value)) {
                throw new RuntimeException('float4 round-trip returned a non-numeric value.');
            }

            $quantized[] = (float) $value;
        }

        return $quantized;
    }
}
