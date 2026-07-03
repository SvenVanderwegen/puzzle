<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * First-party error beacon rows (ADR-0008); purged at 90 days (retention command).
 *
 * @property int $id
 * @property string $message
 * @property string|null $stack
 * @property string|null $route
 * @property Carbon|null $created_at
 */
#[Fillable(['message', 'stack', 'route', 'created_at'])]
class FrontendError extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
