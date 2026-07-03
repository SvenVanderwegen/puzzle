<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Audit trail of content:import runs (contracts/db-schema.sql). Rows are also
 * written for refused signatures (sig_ok = false) so tampering attempts leave
 * a trace.
 *
 * @property int $id
 * @property string $content_version
 * @property string $manifest_sha256
 * @property bool $sig_ok
 * @property Carbon $imported_at
 */
class ContentImport extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sig_ok' => 'boolean',
            'imported_at' => 'datetime',
        ];
    }
}
