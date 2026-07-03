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
            CREATE TABLE puzzles (
                id              text PRIMARY KEY,                -- 'bf1-6x6-000001'
                spec            jsonb NOT NULL,                  -- burnfront.puzzle/1 board object
                rows            smallint NOT NULL,
                cols            smallint NOT NULL,
                n_breaks        smallint NOT NULL,
                grade_tier      text NOT NULL CHECK (grade_tier IN ('lookout','crew','hotshot')),
                grade_score     numeric NOT NULL,
                solution_sha256 text NOT NULL,                   -- sha256 of row-major bit string
                gen_version     text NOT NULL,
                content_version text NOT NULL,
                pack_id         text,
                imported_at     timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE daily_puzzles (
                date             date PRIMARY KEY,               -- UTC day (ADR-0002)
                puzzle_id        text NOT NULL UNIQUE REFERENCES puzzles(id),
                incident_number  integer NOT NULL UNIQUE,        -- sequential from launch
                published_at     timestamptz NOT NULL DEFAULT now(),
                calendar_version text NOT NULL,
                amnesty          boolean NOT NULL DEFAULT false  -- pulled-daily streak amnesty (WS-18)
            );

            CREATE TABLE puzzle_fetches (                        -- anti-cheat time anchor
                user_id    text NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                puzzle_id  text NOT NULL REFERENCES puzzles(id),
                fetched_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (user_id, puzzle_id)
            );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzle_fetches');
        Schema::dropIfExists('daily_puzzles');
        Schema::dropIfExists('puzzles');
    }
};
