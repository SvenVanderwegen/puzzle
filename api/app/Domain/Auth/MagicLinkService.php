<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Mail\MagicLinkMail;
use App\Models\AuthIdentity;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Magic-link issue/consume per ADR-0003: single-use tokens, sha256 at rest,
 * 15-minute TTL, constant behavior whether or not the account exists.
 */
final class MagicLinkService
{
    public const TTL_MINUTES = 15;

    /**
     * Issue a sign-in token. Deliberately identical work for known and unknown
     * emails: no account lookup happens here (no enumeration oracle).
     */
    public function issue(string $email): void
    {
        $email = self::normalizeEmail($email);
        $token = bin2hex(random_bytes(32));

        MagicLinkToken::query()->create([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        Mail::to($email)->send(new MagicLinkMail($token));
    }

    /**
     * Exchange a raw token for a user. Returns null when the token is unknown,
     * expired, or already used. The consumed_at flip is a single conditional
     * UPDATE, so exactly one concurrent request can win the token.
     */
    public function consume(string $token): ?User
    {
        $hash = hash('sha256', $token);

        $claimed = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        if ($claimed === 0) {
            return null;
        }

        /** @var MagicLinkToken $row */
        $row = MagicLinkToken::query()->where('token_hash', $hash)->firstOrFail();

        return $this->findOrCreateUser($row->email);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * First consume creates the account: users row + 'email' auth identity.
     */
    private function findOrCreateUser(string $email): User
    {
        return DB::transaction(function () use ($email): User {
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null) {
                return $existing;
            }

            $user = new User(['email' => $email]);
            $user->id = (string) Str::ulid();
            $user->save();

            AuthIdentity::query()->create([
                'user_id' => $user->id,
                'provider' => 'email',
                'provider_uid' => $email,
            ]);

            return $user;
        });
    }
}
