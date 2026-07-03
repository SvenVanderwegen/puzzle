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
            CREATE TABLE streaks (
                user_id             text PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                current_len         integer NOT NULL DEFAULT 0,
                best_len            integer NOT NULL DEFAULT 0,
                last_daily_date     date,                        -- UTC
                freeze_available_at date,                        -- next date a freeze may be earned
                frozen_dates        date[] NOT NULL DEFAULT '{}',-- days auto-covered by a freeze
                updated_at          timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE daily_stats (                            -- percentile aggregates (no names)
                date         date PRIMARY KEY REFERENCES daily_puzzles(date),
                solved_count integer NOT NULL DEFAULT 0,
                started_count integer NOT NULL DEFAULT 0,
                p50_ms       integer,
                histogram    jsonb,                               -- solve-time buckets
                updated_at   timestamptz NOT NULL DEFAULT now()
            );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
        Schema::dropIfExists('streaks');
    }
};
