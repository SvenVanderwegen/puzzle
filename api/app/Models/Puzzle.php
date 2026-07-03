<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PuzzleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Imported burnfront.puzzle/1 content (contracts/db-schema.sql). `spec` holds
 * the board object only; the solution is never stored — solution_sha256 is the
 * corruption tripwire for valid solves (WS-07).
 *
 * @property string $id
 * @property array<string, mixed> $spec
 * @property int $rows
 * @property int $cols
 * @property int $n_breaks
 * @property string $grade_tier
 * @property string $grade_score
 * @property string $solution_sha256
 * @property string $gen_version
 * @property string $content_version
 * @property string|null $pack_id
 * @property Carbon $imported_at
 */
#[UseFactory(PuzzleFactory::class)]
class Puzzle extends Model
{
    /** @use HasFactory<PuzzleFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'rows' => 'integer',
            'cols' => 'integer',
            'n_breaks' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}
