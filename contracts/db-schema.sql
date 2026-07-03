-- contracts/db-schema.sql — the frozen table baseline (ADR-0005).
-- api/ migrations must produce a schema whose dump diffs clean against this.
-- Baseline tables per ADR-0005; magic_link_tokens, daily_stats, events and
-- frontend_errors are the operational tables required by WS-06/07/19 briefs
-- (blessed together with this file by the freeze ADR).
-- Conventions: ULIDs as text PKs for user-facing ids; timestamptz everywhere;
-- UTC day boundaries (ADR-0002). Laravel framework tables (jobs, failed_jobs,
-- cache, sessions-in-Redis) are not part of the contract.

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
    endless_spec    jsonb,                           -- board object for mode='endless'
    response_snapshot jsonb,                         -- idempotent replay of the response
    CONSTRAINT solves_user_client_unique UNIQUE (user_id, client_solve_id)
);
CREATE UNIQUE INDEX solves_one_valid_daily
    ON solves (user_id, puzzle_id) WHERE mode = 'daily' AND valid;
CREATE INDEX solves_puzzle_valid_idx ON solves (puzzle_id, valid, official_ms);
CREATE INDEX solves_user_received_idx ON solves (user_id, received_at);

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
