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
            CREATE TABLE solves (
                id              bigserial PRIMARY KEY,
                user_id         text REFERENCES users(id) ON DELETE SET NULL,  -- NULL after anonymize
                puzzle_id       text REFERENCES puzzles(id),     -- NULL for endless
                mode            text NOT NULL CHECK (mode IN ('daily','pack','endless')),
                client_solve_id uuid NOT NULL,                   -- Idempotency-Key (uuidv7)
                shaded_bits     bytea NOT NULL,
                client_ms       integer NOT NULL,
                official_ms     integer,
                started_at      timestamptz,
                received_at     timestamptz NOT NULL DEFAULT now(),
                valid           boolean NOT NULL,
                reject_reason   text,                            -- BurnVerdictReason when invalid
                suspect         boolean NOT NULL DEFAULT false,  -- clock lies: percentile-ineligible
                imported        boolean NOT NULL DEFAULT false,  -- via /me/import: percentile-ineligible
                hints_s1        smallint NOT NULL DEFAULT 0,
                hints_s2        smallint NOT NULL DEFAULT 0,
                hints_s3        smallint NOT NULL DEFAULT 0,
                undo_count      integer NOT NULL DEFAULT 0,
                replay          bytea,                           -- gzip event log; purged at 90 days
                replay_sha256   text,
                ip_hash         text,                            -- sha256+pepper; purged at 90 days
                ua_hash         text,
                endless_spec    jsonb,                           -- mode='endless': board object +
                                                                 -- client deduction_steps (RATING.md §4)
                response_snapshot jsonb,                         -- idempotent replay of the response
                CONSTRAINT solves_user_client_unique UNIQUE (user_id, client_solve_id)
            );
            CREATE UNIQUE INDEX solves_one_valid_daily
                ON solves (user_id, puzzle_id) WHERE mode = 'daily' AND valid;
            CREATE INDEX solves_puzzle_valid_idx ON solves (puzzle_id, valid, official_ms);
            CREATE INDEX solves_user_received_idx ON solves (user_id, received_at);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('solves');
    }
};
