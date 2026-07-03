# WS-11 STATUS

## Session 2026-07-03 — builder

## Done

Commit `cecdbdf` (base `50803ce`, everything below is in that one commit):

- `/play` Endless mode implemented in `apps/web/src/endless/` + `PlayPage.tsx` rewrite.
- **Generation in a Web Worker**: `generator.worker.ts` is the ONLY module importing the
  engine's `generate()`/`grade()` (grep-tripwired in `boundary.test.ts`; the built main
  bundle verifiably contains no generator code — worker chunk is a separate 3.4 KB gz
  asset, precached by the PWA sw). Seed drawn from `crypto.getRandomValues` at the app
  boundary, sfc32 Rng injected into the engine (rule 8).
- **Race-free cancel/regenerate**: token protocol (`protocol.ts` + `GeneratorClient`);
  superseded requests reject with `GenerationCancelled`, stale worker responses are
  dropped by token. Covered by client unit tests (rapid re-requests, cancel, stale drop)
  and a UI test (dial switch mid-generation never surfaces the superseded board).
- **Pre-generation**: one-board cache filled while the player solves; “next” resolves
  from cache with no worker round-trip (tested).
- Tier dials Lookout 5×5/4 · Crew 6×6/8 · Hotshot 7×7/12 (`params.ts`), recommended tier
  highlighted from the local rating band (`hub/tiers.ts`), rotating fairness loading copy
  `play.loading.endless.1-4` (`loadingCopy.ts`, 1.2 s cadence, loops).
- Play flow: game-core `PlaySession` + ui-web `Board`; wrong-shading `play.wrong` line;
  win → `BurnReplay` + stats card (time, clean-contain, per-tier solved count, guest note).
- **Rated submission (signed-in)**: game-core `assembleSolveRecord` → `endless_spec` wire
  board ([r,c] positions), REQUIRED `deduction_steps` (ADR-0020), UUID v7
  Idempotency-Key (ADR-0021, game-core `uuidV7`); POST `/solves` via `@burnfront/api-client`
  only; on `rating_pending` → one GET `/me/rating` refresh → calibrating line or
  rating+sparkline-delta chip. 401 degrades to guest flow; 422→`error.generic`,
  429→`error.rateLimited`. Guests skip submission entirely (asserted).
- **Persistence**: dial + per-tier history (solved/best/last ms) under
  `burnfront.endless.v1`; hub-facing `endless.{tier,inProgress,solvedByTier}` synced in
  `burnfront.local.v1` (Resume-Endless button + lane counts stay correct); mid-solve board
  as a game-core SessionSnapshot under `burnfront.endless.session.v1` via
  `loadSnapshot/saveSnapshot/clearSnapshot` and a StorageLike→KeyValueStorage adapter.
  Resume across reload restores marks + elapsed + deductionSteps (tested).
- Tests: 23 files in `src/endless/` (114 new tests; apps/web suite 130 → 260, all green).
  Feature coverage 97.25 % lines (floor 70 %); apps/web overall 96.33 %.
- Gates all green: `pnpm -r typecheck` · `pnpm -r lint` · `pnpm -r test`
  (12+52+143+58+260 = 525) · `format:check` · `hygiene` · `strings:check` ·
  `budget:check` (initial JS 107.56 KB gz ≤ 200 KB; was 94.53 baseline) ·
  `budget:landing` (committed artifact untouched and still byte-fresh).

## Remaining

- Verifier session: sign off the brief's acceptance checklist (not self-signed).
- Lead (copy governance, ADR-0017/0022 path): add two COPY.md keys —
  `endless.new` ("New terrain") and `endless.rating.pending` ("Rating update queued.") —
  then regenerate `api/resources/landing/hero.js` (`pnpm --filter @burnfront/web
  build:landing`) in the same pass, and swap the two v1 stand-ins in `EndlessPlay.tsx`
  (see Decisions #3).
- Phase-2 surface (out of scope, non-goals): seed sharing/puzzle codes; board-rating
  display ("This terrain: 1310") needs a contract addition — see Decisions #5.

## Blockers

None.

## Decisions made

1. **Tier dials carry `minClues` 5/8/12** (lookout/crew/hotshot) — the same clue floors
   the engine's own perf bounds are certified at (`packages/engine/src/perf.test.ts`),
   keeping worker generation fast and difficulty tier-shaped.
2. **`deduction_steps` = engine `grade()` run in the worker** on the generated board.
   (Identical by construction to `GeneratedPuzzle.deductionSteps`; grading in the worker
   keeps every engine-solver call off the main thread and honors "from engine grade()".)
3. **No new proposed copy keys this session.** Adding keys to
   `src/strings/proposed.ts` changes the merged catalog that `landing/HeroApp.tsx`
   bundles, which makes the committed `api/resources/landing/hero.js` byte-stale and
   fails `budget:landing` — and `api/` is outside WS-11's declared paths. V1 stand-ins:
   the regenerate/next control reuses catalog key `hub.play.endless`
   ("Keep burning · {tier}" — same action semantics: fresh board at this tier), and the
   rating-pending state is a glyph-only "…" chip (`data-testid="rating-pending"`).
   Lead follow-up in Remaining.
4. **Storage layout** as described in Done (feature prefs key + LocalState sync + game-core
   snapshot). `dial: null` means "follow the rating recommendation", preserving the WS-09
   stub behavior for users who never touched the dials. The KeyValueStorage adapter
   tombstones with an empty string when the injected StorageLike lacks `removeItem`
   (real localStorage uses true removal).
5. **Board rating is NOT shown** ("This terrain: 1310" from the brief): the frozen
   `SolveResult` schema carries no board rating and no endpoint exposes one. Shipped
   instead: rating_pending indicator → single `/me/rating` refresh → calibrating
   (`play.stats.calibrating`) or `play.stats.ratingDelta` with the delta from the
   sparkline's last movement. Exposing board rating = contract change = ADR (lead).
6. **No polling loop** on `/me/rating` — exactly one refresh after `rating_pending`. The
   Glicko job is queued server-side; if the refresh races it, the pending chip stays (the
   hub/`/me` show the settled number next visit). Simplicity per the brief's "keep simple".
7. **No lazy route split**: endless adds +13 KB gz to the initial bundle
   (94.53 → 107.56 KB gz, budget 200 KB); the generator itself is already split into the
   worker chunk. Lazy-loading deferred until the budget nears the line.
8. **Tier switch mid-solve abandons the board without confirmation** (product §4: no
   confirm dialogs in play; RATING.md §3: endless abandons are unrated, no punishment).
9. **Coach/hints not wired into endless v1** — not in the brief's scope; hint counts
   submit as zeros (clean contains). Coach surface remains WS-10/12 territory.
10. **Injected feature deps** via `EndlessDepsContext` (`deps.tsx`, same pattern as
    `state/runtime.tsx`): worker factory, seed source, api surface, solve-record env
    (gzip `CompressionStream`, WebCrypto sha256, crypto Rng) — tests mock all of it.
11. Default worker factory returns `null` where `Worker` is unavailable (test DOMs);
    the page stays in the loading state rather than generating on the main thread.

## Files touched

New:
- `apps/web/src/endless/{EndlessPlay.tsx, api.ts, deps.tsx, generator.worker.ts,
  generatorClient.ts, loadingCopy.ts, params.ts, prefs.ts, protocol.ts, rng.ts,
  storage.ts, submit.ts, webDeps.ts, workerFactory.ts}`
- Tests: `apps/web/src/endless/{api,boundary,endless,generatorClient,loadingCopy,prefs,
  rng,submit,worker}.test.*`
- `tasks/WS-11/STATUS.md` (this file)

Modified:
- `apps/web/src/routes/PlayPage.tsx` (stub → EndlessPlay mount + tier resolution
  search → persisted dial → rating recommendation)

Explicitly NOT touched: `contracts/`, `packages/`, `api/`, CI, `src/strings/*`
(proposed-key attempt reverted — Decisions #3), `pnpm-lock.yaml` (no new deps).

## Resume instructions

Fresh agent: the feature is complete and all gates pass. To verify from zero:
`pnpm install && pnpm -r typecheck && pnpm -r lint && pnpm -r test && pnpm format:check
&& pnpm hygiene && pnpm --filter @burnfront/web strings:check && pnpm --filter
@burnfront/web budget:check && pnpm --filter @burnfront/web budget:landing`.
Exercise manually: `pnpm --filter @burnfront/web dev` → `/play` (worker generation needs
a real browser; happy-dom has no Worker). Next actions are in Remaining (verifier session;
lead copy-key follow-up). The brief's acceptance boxes stay unchecked until the verifier
signs them.
