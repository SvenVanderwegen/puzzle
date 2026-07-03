<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract group (ADR-0005): SQL is verbatim from contracts/db-schema.sql.
 * The schema-conformance gate (tests/schema-conformance.sh) diffs a pg_dump of this
 * group against the contract file; any drift here is a contract change and needs an ADR.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS citext;

            CREATE TABLE users (
                id            text PRIMARY KEY,                  -- ULID
                email         citext UNIQUE,                     -- NULL after anonymization
                handle        citext UNIQUE,                     -- reserved; never exposed in v1 (ADR-0007)
                timezone      text NOT NULL DEFAULT 'UTC',       -- ONLY for streak-risk email send time
                country       char(2),
                plan          text NOT NULL DEFAULT 'free',      -- entitlement door (no billing in v1)
                pro_until     timestamptz,
                streak_alert_opt_in boolean NOT NULL DEFAULT false,  -- double opt-in (WS-21)
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                anonymized_at timestamptz                        -- delete = anonymize (ADR + gdpr.md)
            );

            CREATE TABLE auth_identities (
                id           bigserial PRIMARY KEY,
                user_id      text NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                provider     text NOT NULL,                      -- 'email' in v1; additive later
                provider_uid text NOT NULL,
                created_at   timestamptz NOT NULL DEFAULT now(),
                UNIQUE (provider, provider_uid)
            );

            CREATE TABLE magic_link_tokens (
                id          bigserial PRIMARY KEY,
                email       citext NOT NULL,                     -- pre-account: no user FK
                token_hash  text NOT NULL UNIQUE,                -- sha256; raw token never stored
                expires_at  timestamptz NOT NULL,                -- now() + 15 min (ADR-0003)
                consumed_at timestamptz,                         -- single-use
                created_at  timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX magic_link_tokens_email_created_idx ON magic_link_tokens (email, created_at);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_link_tokens');
        Schema::dropIfExists('auth_identities');
        Schema::dropIfExists('users');
    }
};
