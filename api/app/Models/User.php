<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Magic-link-only identity (ADR-0003): no password, no remember token.
 * ULID text primary key; erasure = anonymization (contracts/db-schema.sql).
 *
 * @property string $id
 * @property string|null $email
 * @property string|null $handle
 * @property string $timezone
 * @property string|null $country
 * @property string $plan
 * @property Carbon|null $pro_until
 * @property bool $streak_alert_opt_in
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $anonymized_at
 */
#[Fillable(['email', 'timezone', 'streak_alert_opt_in'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pro_until' => 'datetime',
            'streak_alert_opt_in' => 'boolean',
            'anonymized_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        // Magic-link only (ADR-0003); nothing password-like is ever stored.
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // No remember-me tokens: the session cookie is the only credential.
    }
}
