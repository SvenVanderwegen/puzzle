<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Magic-link-only users (ADR-0003): no password, no remember token.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::ulid(),
            'email' => 'crew-'.Str::lower(Str::random(12)).'@example.test',
            'timezone' => 'UTC',
            'plan' => 'free',
            'streak_alert_opt_in' => false,
        ];
    }

    public function anonymized(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => null,
            'anonymized_at' => now(),
        ]);
    }
}
