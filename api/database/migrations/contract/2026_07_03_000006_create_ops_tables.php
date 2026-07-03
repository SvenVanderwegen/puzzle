<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract group (ADR-0005): SQL is verbatim from contracts/db-schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE content_imports (
                id              bigserial PRIMARY KEY,
                content_version text NOT NULL,
                manifest_sha256 text NOT NULL,
                sig_ok          boolean NOT NULL,
                imported_at     timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE events (                                 -- first-party analytics (ADR-0008)
                id         bigserial PRIMARY KEY,
                anon_id    text NOT NULL,                         -- rotating-free localStorage id
                user_id    text,                                  -- nullable; no FK: survives anonymize
                name       text NOT NULL,
                props      jsonb NOT NULL DEFAULT '{}',
                created_at timestamptz NOT NULL DEFAULT now()
                -- aggregated then row-purged at 13 months (gdpr.md retention)
            );
            CREATE INDEX events_name_created_idx ON events (name, created_at);

            CREATE TABLE frontend_errors (                        -- first-party beacon (ADR-0008)
                id         bigserial PRIMARY KEY,
                message    text NOT NULL,
                stack      text,
                route      text,
                created_at timestamptz NOT NULL DEFAULT now()
                -- purged at 90 days
            );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('frontend_errors');
        Schema::dropIfExists('events');
        Schema::dropIfExists('content_imports');
    }
};
