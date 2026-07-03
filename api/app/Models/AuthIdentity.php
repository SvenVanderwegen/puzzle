<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Login identity rows: provider 'email' in v1; social providers are additive later (ADR-0003).
 *
 * @property int $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_uid
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'provider', 'provider_uid'])]
class AuthIdentity extends Model
{
    protected $table = 'auth_identities';

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
