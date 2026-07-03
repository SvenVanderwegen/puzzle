# WS-14 STATUS — Accounts UI + GDPR self-service + legal routes

## Session 2026-07-03 (builder)

## Done
Commit: (this worktree branch `worktree-agent-aa23aa1115a199a7c`, single commit — SHA in `git log`)

- **/login (ADR-0003)** — `apps/web/src/routes/LoginPage.tsx`:
  - Request form: labelled email input → `POST /auth/magic-link` → the constant
    `auth.sent` state on 202 (account or not — no enumeration; the form is
    removed so nothing invites probing). 429 → `error.rateLimited` (role=alert),
    thrown `ApiError`/network → `error.generic`; form stays usable.
  - Consumed-link landing: `/login?token=…` (route `validateSearch` added in
    `router.tsx`) → `POST /auth/magic-link/consume`; 204 → `GET /me` → local
    signed-in marker (`withAccount`) → redirect to `/` with the
    `auth.consumed` toast (flash via router history state, `chrome/flash.ts` —
    no module-level mutable store). 410 → `auth.expired` (role=alert) + the
    request form as the retry path. Double-effect guarded (single-use token
    vs StrictMode).
  - Timezone bootstrap: right after consume, if the profile sits at the
    server default (`UTC`) and the browser detects a different IANA zone
    (`Intl`), one `PATCH /me {timezone}` (see Decisions #4).
- **/me** — `apps/web/src/routes/MePage.tsx`: guest = local provisional
  record + `streak.guestNote`, ZERO api calls (tested). Signed in = `GET /me`
  (+`/me/solves` in parallel): rating chip (calibrating `n/10` per RATING.md
  §5, else rounded rating + delta from the last two sparkline points),
  SVG sparkline (token colors), streak chip + `streak.frozen` marker when a
  live streak's `last_daily_date` is older than yesterday (freeze held it),
  cursor-paged history (`me.history.more` → `?cursor=`), distributions
  placeholder line (`me.distributions.pending` — no contract endpoint yet).
  401 → marker cleared, guest view.
- **/settings** — `apps/web/src/routes/SettingsPage.tsx`:
  - Device prefs (local-only, new `prefs` field in `state/localState.ts`):
    sound / reduced motion / hide-timer / high-contrast. Applied live by
    `AppChrome` as `data-contrast` / `data-motion` attributes (high-contrast
    CSS in `appCss.ts`, tokens only). WS-10/11 read `prefs.sound` /
    `prefs.hideTimer` from local state.
  - Account rows (session only; guests get a `/login` pointer and make zero
    calls): `settings.streakAlert` checkbox + IANA timezone select
    (`Intl.supportedValuesOf`, UTC always offered) → `PATCH /me`;
    **export** `GET /me/export` → 202 → `settings.export.sent`
    (auth.sent-style); **delete** = type-to-confirm dialog
    (`settings.delete.explain`, word `settings.delete.word`, confirm
    disabled until exact match, focus into the field on open / back to the
    opener on close, Escape closes, `aria-modal`) → `DELETE /me` → marker
    cleared, dialog closed, focus moved to the `settings.delete.done`
    notice; **local guest state preserved byte-for-byte** (tested);
    sign-out `POST /auth/logout` → marker cleared, record kept.
- **Nudges (product §1 — exactly three, never modal-blocking)** —
  `apps/web/src/account/nudges.ts` (3-state decision: signed-in → none;
  guest streak <3 → `guest-note`; ≥3 → `streak-protect`) +
  `account/PostSolveNudge.tsx` (footer line / `streak.protect` link to
  `/login`) for WS-10/11 to mount in the stats-card footer. Nudge 3 = the
  persistent header Guest chip (already linked `/login`; now tagged
  `data-nudge="guest-chip"`, tested).
- **Runtime plumbing** — `state/runtime.tsx`: local state is now a
  per-storage subscribe store (`useSyncExternalStore`) so sign-in/prefs
  writes re-render every consumer; `useLocalStateUpdate()`; `api` slot in
  the injected `Runtime` (`useApi()`, real `createApiClient` in the browser,
  mock in tests). `localState.ts`: `prefs`, `withAccount`, `withoutAccount`,
  merge-over-defaults extended.
- **Legal routes** (WS-15 shipped none — footer links 404'd):
  `api/routes/web.php` + `Route::view` (route:cache-safe) for
  `/privacy` `/terms` `/imprint`; Blade views in
  `api/resources/views/legal/` extending the WS-15 landing layout
  (canonicals, zero third-party); agent-drafted copy with visible
  `[owner review: …]` placeholders; full drafts with `TODO(owner)` markers in
  `docs/legal/{privacy,terms,imprint}.md` (Belgian imprint fields: name,
  address, email, BCE/KBO, VAT). `api/tests/Feature/Legal/LegalPagesTest.php`
  (7 tests / 15 datasets): routes render, canonicals, review markers
  asserted present until the owner fills them, privacy claims match the real
  endpoints (24h single-use link, 90-day retention, DPA), Belgian fields,
  footer links, zero third-party requests.
- **Tests** — apps/web 172 pass (13 files; new: `LoginPage.test.tsx`,
  `SettingsPage.test.tsx`, `MePage.test.tsx`, `account/nudges.test.tsx`,
  extended `localState.test.ts`, updated `app.test.tsx` for the built
  pages). New test harness `src/testing/{mockApi,renderApp}` (coverage-
  excluded in vitest.config). PHP suite 191 pass.

## Gates (all green this session)
- `pnpm -r typecheck` ✓ · `pnpm -r lint` ✓ · `pnpm -r test` ✓
  (apps/web 172 tests, **94.78% lines** — floor 70%; engine 99.33%,
  game-core 99.28%, ui-web 98.67%)
- `pnpm format:check` ✓ · `pnpm hygiene` ✓
- `pnpm --filter @burnfront/web strings:check` ✓ ·
  `budget:check` ✓ (initial JS **98.71 KB gz** ≤ 200 KB; zero third-party;
  PWA shell precached)
- api touched → `php artisan test` ✓ (191 passed / 2704 assertions) ·
  `vendor/bin/pint --test` ✓ · `vendor/bin/phpstan analyse` (level 9) ✓
  (scratch Postgres on 127.0.0.1:55432 per tasks/WS-06/STATUS.md; local
  `api/.env` created from `.env.example` + `key:generate` — gitignored,
  fake values only)

## Remaining
- **Mailpit e2e** for the full auth loop (request → mail → consume →
  session → logout) and the delete/export loops — WS-17 Playwright harness
  territory; the mocked-client feature tests cover the SPA side, and
  server-side single-use/expiry/anonymization semantics are already pinned
  by WS-06's `api/tests/Feature/Me/{ExportTest,AnonymizeTest}.php`.
- **CSP report-only wiring** (brief output): needs middleware in
  `api/app/**`, which this session's path allowlist excluded. The
  zero-third-party HTML assertions (legal pages here, landing in WS-15,
  SPA dist in budget:check) cover the observable half; the header itself is
  open. Suggest attaching to WS-22 (which hardens report-only → enforce).
- Owner + lawyer review of `docs/legal/*` drafts; fill every `TODO(owner)`
  and `[owner review: …]`, then flip LegalPagesTest's marker assertions to
  `assertDontSee`.
- Lead: move the 21 proposed copy keys into contracts/COPY.md by ADR
  (ADR-0017 pattern), then dissolve `proposed.ts` and collapse
  `StringKey` back to `CatalogKey`.

## Blockers
- None.

## Decisions made (lead: please audit)
1. **21 proposed copy keys** quarantined in
   `apps/web/src/strings/proposed.ts` (ADR-0017 pattern) — the catalog has
   no strings for: login form label/consume states (`auth.email`,
   `auth.consuming`, `auth.expired`, `auth.signOut`), settings toggles/rows
   (`settings.sound`, `settings.reducedMotion`, `settings.hideTimer`,
   `settings.highContrast`, `settings.timezone`, `settings.timezone.hint`,
   `settings.export.sent`, `settings.delete.typeToConfirm`,
   `settings.delete.word`, `settings.delete.done`, `common.cancel`), and
   /me (`me.history`, `me.history.empty`, `me.history.more`,
   `me.mode.endless`, `me.mode.pack`, `me.distributions.pending`).
   Dispatcher voice kept (no exclamation marks, short declaratives).
2. **CSRF bootstrap documented, wrapper untouched**: the type-locked client
   cannot model `GET /sanctum/csrf-cookie` (outside openapi `paths`; the
   mission said extend only if already modeled). The app injects
   `getCsrfToken` reading the `XSRF-TOKEN` cookie (`state/runtime.tsx`) —
   the same-origin Laravel responses set it. If WS-16/17 integration finds a
   first-mutation-without-cookie path, either the shell route must set the
   cookie or an ADR adds the endpoint to the contract.
3. **Toast via router history state** (`chrome/flash.ts`), runtime-validated
   key allowlist — avoids a module-level mutable store and dies with its
   history entry. `@tanstack/history`'s `HistoryState` is not augmentable
   from here (transitive dep under pnpm), hence the one documented cast pair.
4. **Timezone default semantics**: browser-detected zone is PATCHed once
   right after a successful consume, and only when the profile is at the
   server default (`UTC`) — an explicit /settings choice is never
   overridden by visiting /settings; a fresh sign-in re-detects (accepted
   edge: a user who deliberately chose UTC and signs in again from a
   non-UTC browser gets re-detected).
5. **DELETE /me 401 treated as success** (session already gone → guest
   either way); same for logout. Network failure on sign-out does NOT clear
   the marker (surfaces `error.generic` instead) — silently "signing out"
   while the server session lives felt worse on shared machines.
6. **Legal pages via `Route::view`** + Blade-side `@php` for
   `$baseUrl`/`$boardCss` (pattern precedent: `errors/404.blade.php`) — no
   controller (`api/app/**` untouched), no closures (route:cache-safe).
   Blade placeholders use `[owner review: …]`, never the literal T-O-D-O
   (hygiene greps `*.php` including blade).
7. **streak.frozen render rule** (contract exposes no explicit "frozen
   today" flag): shown when `current > 0` and `last_daily_date` is before
   yesterday (UTC, injected clock) — exactly the state a freeze must have
   covered.
8. **`useLocalState` upgraded to a live store** (useSyncExternalStore, one
   store per storage instance). Read API unchanged; consumers now re-render
   on writes — required for the header chip/prefs to react to WS-14 writes.
9. Test-only harness `src/testing/` excluded from coverage in
   `vitest.config.ts` (keeps the 70% floor honest).
10. **WS-20 seam**: `data-ws="WS-20"` on the consume-landing status element
    + code comment in `LoginPage.tsx` (`ConsumeLanding`) — the local-record
    import (`POST /me/import` + `account.merge.summary` toast) attaches
    right before the hub redirect. Import NOT implemented here (mission
    order; WS-01c cut self-serve email change from v1 — none shipped).

## Files touched
- `apps/web/src/routes/{LoginPage,MePage,SettingsPage}.tsx` (stubs → features)
  + new `.test.tsx` for each
- `apps/web/src/account/{nudges.ts,PostSolveNudge.tsx,timezone.ts,nudges.test.tsx}` (new)
- `apps/web/src/chrome/{AppChrome.tsx,appCss.ts}` (toast, prefs attrs,
  WS-14 CSS), `apps/web/src/chrome/flash.ts` (new)
- `apps/web/src/state/{localState.ts,localState.test.ts,runtime.tsx}`
- `apps/web/src/strings/{proposed.ts,index.ts}`
- `apps/web/src/{router.tsx,app.test.tsx}`, `apps/web/vitest.config.ts`
- `apps/web/src/testing/{mockApi.ts,renderApp.tsx}` (new, test-only)
- `api/routes/web.php` (3 `Route::view` legal routes),
  `api/resources/views/legal/{privacy,terms,imprint}.blade.php` (new),
  `api/tests/Feature/Legal/LegalPagesTest.php` (new)
- `docs/legal/{privacy,terms,imprint}.md` (new drafts, TODO(owner) markers)
- `tasks/WS-14/STATUS.md` (this file)

## Resume instructions
1. Nothing in-flight; the branch is committed and all gates are green.
2. Verifier: run the gates above (PHP needs the scratch Postgres —
   tasks/WS-06/STATUS.md resume step 1 — plus `cp .env.example .env &&
   php artisan key:generate` inside `api/`), then execute the brief's
   acceptance checklist. The two e2e items (auth loop via mailpit; delete
   anonymization e2e) are satisfiable only from the WS-17 harness — the
   feature-level equivalents are listed under Done/Remaining.
3. Lead: ADR for the proposed copy keys (Decisions #1) and a home for the
   CSP report-only header (Remaining).

---

## Session 2026-07-03 (builder — fix-up after verification, lead-directed)

## Done
Commit: (fix-up commit on this branch, on top of `e73e94e` — SHA in `git log -1`)

1. **Delete-dialog focus trap** (verifier gap: `aria-modal` alone let Tab
   escape) — `SettingsPage.tsx` `trapTab`: Tab/Shift+Tab wrap at the dialog
   edges (focusables = enabled `a[href]/button/input/select/textarea`
   inside the dialog, so the trailing edge moves when the confirm button
   enables); stray focus outside the dialog is pulled back to the first
   focusable. Escape/focus-in-on-open/focus-return-on-close/focus-to-done-
   notice all unchanged. New test (SettingsPage.test.tsx): wraps forward
   and backward at both edges, disabled-confirm and enabled-confirm
   states, background (sound toggle) never focused.
2. **Sanctum CSRF bootstrap** (lead ruling: one hand-written
   `GET /sanctum/csrf-cookie` sanctioned as transport plumbing; ADR at
   integration) — homed in **`packages/api-client/src/client.ts`**
   (`bootstrapCsrfCookie` inside `createApiClient`; scope grant used;
   types.gen.ts untouched). Behavior: runs only when a MUTATING call finds
   `getCsrfToken() === null` (and only when a token source is injected —
   bare node/test clients never fire it); in-flight promise deduped so
   concurrent mutations share one GET; re-armed after settling so a
   still-missing cookie is retried on the next mutating call; failures
   swallowed (the caller's request surfaces the real error); token re-read
   after bootstrap. New option `csrfBootstrapUrl` (default
   '/sanctum/csrf-cookie'). 5 new unit tests in client.test.ts: no-op with
   cookie present · fires-once-then-fresh-token · concurrent dedupe (gated
   fetch, exactly one bootstrap, both mutations carry the fresh token) ·
   never for GET / never without a token source · failed bootstrap
   swallowed + re-armed. `runtime.tsx` seam comment updated (decision #2
   of the first session is RESOLVED by this ruling).
3. **Privacy retention figure** — "aggregated into anonymous statistics and
   purged after at most 13 months" (decisions.md #7) in
   `docs/legal/privacy.md` + `legal/privacy.blade.php` (kept in sync);
   LegalPagesTest now asserts the 13-month claim. TODO(owner) markers kept.
4. **`api/resources/landing/hero.js` untouched** per lead instruction —
   `budget:landing` freshness failure remains lead-owned (rebuilt at
   integration with the COPY.md key ADR).

## Gates (re-run, all green except the known lead-owned one)
- `pnpm -r typecheck` ✓ · `pnpm -r lint` ✓ · `pnpm -r test` ✓ —
  apps/web **173** (94.74% lines) · api-client **17** (98.97%) · engine 52
  (99.33%) · game-core 143 (99.26%) · ui-web 58 (98.67%)
- `pnpm format:check` ✓ · `pnpm hygiene` ✓ · `strings:check` ✓
- `budget:check` ✓ — initial JS **99.00 KB gz** ≤ 200 KB (was 98.71;
  +~0.3 KB from the bootstrap)
- `php artisan test` ✓ **191 passed / 2705 assertions** · `pint --test` ✓ ·
  `phpstan` level 9 ✓
- `budget:landing`: NOT run/fixed — expected to fail on hero.js freshness
  (strings quarantine); lead rebuilds at integration.

## Blockers
- None.

## Decisions made (lead: please audit)
11. Bootstrap homed in the api-client wrapper (not runtime.tsx): apps/web
    lint bans `fetch` outright (CLAUDE.md rule 2 guard), the wrapper already
    owns transport (headers/credentials/CSRF header), and the dedupe must
    sit beside the request path to cover ALL mutating callers. Only fires
    when `getCsrfToken` is injected — the browser runtime injects it;
    plain test clients keep exact prior behavior.
12. Dedupe re-arms after settling (in-flight-only dedupe, per the ruling's
    wording): sequential mutating calls with a still-absent cookie retry
    the bootstrap; a successful bootstrap makes later calls no-ops via the
    cookie itself.

## Files touched (this session)
- `packages/api-client/src/client.ts` (+ client.test.ts) — scope grant
- `apps/web/src/routes/SettingsPage.tsx` (+ SettingsPage.test.tsx)
- `apps/web/src/state/runtime.tsx` (comment only)
- `docs/legal/privacy.md`, `api/resources/views/legal/privacy.blade.php`,
  `api/tests/Feature/Legal/LegalPagesTest.php`
- `tasks/WS-14/STATUS.md`

## Resume instructions
1. Branch committed, not pushed (lead instruction). All gates green except
   the lead-owned `budget:landing` (expected).
2. Integration (lead): COPY.md key ADR + hero.js rebuild + the CSRF
   bootstrap ADR (ruling recorded above).
