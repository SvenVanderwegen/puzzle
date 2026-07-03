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
            CREATE TABLE ratings (
                user_id    text PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                rating     real NOT NULL DEFAULT 1500,
                rd         real NOT NULL DEFAULT 350,
                volatility real NOT NULL DEFAULT 0.06,
                games      integer NOT NULL DEFAULT 0,
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE board_ratings (
                puzzle_id  text PRIMARY KEY REFERENCES puzzles(id),
                rating     real NOT NULL,                        -- seeded from grade (RATING.md §priors)
                rd         real NOT NULL DEFAULT 200,
                volatility real NOT NULL DEFAULT 0.06,
                attempts   integer NOT NULL DEFAULT 0,
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE rating_events (                          -- full audit: deterministic recompute
                id           bigserial PRIMARY KEY,
                solve_id     bigint NOT NULL REFERENCES solves(id),
                user_id      text,                                -- kept nullable for anonymization
                puzzle_id    text,
                score        real NOT NULL,                       -- outcome s in [0,1]
                weight       real NOT NULL,                       -- 1.0 daily/pack, 0.5 endless
                user_before  real NOT NULL, user_after  real NOT NULL,
                user_rd_before real NOT NULL, user_rd_after real NOT NULL,
                board_before real NOT NULL, board_after real NOT NULL,
                created_at   timestamptz NOT NULL DEFAULT now()
            );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_events');
        Schema::dropIfExists('board_ratings');
        Schema::dropIfExists('ratings');
    }
};
