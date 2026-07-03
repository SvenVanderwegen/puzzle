<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SolveFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Solve submissions (WS-07 owns the write path). WS-06 touches this table only for
 * GDPR: anonymization sets user_id NULL; retention nulls replay/ip_hash/ua_hash at 90 days.
 *
 * @property int $id
 * @property string|null $user_id
 * @property string|null $puzzle_id
 * @property string $mode
 * @property string $client_solve_id
 * @property mixed $shaded_bits
 * @property int $client_ms
 * @property int|null $official_ms
 * @property Carbon|null $started_at
 * @property Carbon $received_at
 * @property bool $valid
 * @property string|null $reject_reason
 * @property bool $suspect
 * @property bool $imported
 * @property int $hints_s1
 * @property int $hints_s2
 * @property int $hints_s3
 * @property int $undo_count
 * @property mixed $replay
 * @property string|null $replay_sha256
 * @property string|null $ip_hash
 * @property string|null $ua_hash
 * @property array<string, mixed>|null $endless_spec
 * @property array<string, mixed>|null $response_snapshot
 */
#[UseFactory(SolveFactory::class)]
class Solve extends Model
{
    /** @use HasFactory<SolveFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'received_at' => 'datetime',
            'valid' => 'boolean',
            'suspect' => 'boolean',
            'imported' => 'boolean',
            'endless_spec' => 'array',
            'response_snapshot' => 'array',
        ];
    }
}
