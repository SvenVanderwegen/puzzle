# WS-10 STATUS — Daily Burn Order + streak UI + share + unfurl

## Done
Builder session (SPA daily feature) + lead completion under the session-limit
outage (offline fix, test hardening, the Blade unfurl half, gates).

- SPA daily feature `apps/web/src/daily/**`: DailyPlay (load → play → win →
  stats → share → tomorrow tease), content fetch with CDN-first + origin
  fallback (`content.ts`, honoring the WS-07 `origin_fallback` flag), startDaily
  anti-cheat anchor, submit via api-client with UUIDv7 Idempotency-Key and
  offline/reconnect retry of the ONE in-progress daily (`submit.ts`,
  `pendingSubmission.ts`), StreakFlame with the freeze "controlled burn" ring,
  past-date play (7-day window, no streak credit), guest daily history via
  `appendSolveLog` (WS-20 seam), UTC countdown pre-solve.
- Share (`share.ts`): burn-signature emoji per product §6 (🟥 ≥4 / 🟧 2–3 /
  🟨 1 per minute; ⏱ time; ✅ clean-only; 🔥 streak≥2; no card on fail),
  navigator.share + clipboard fallback. Spoiler-freeness (acceptance #1) is
  test-proven: the solution bitstring and every A1 cell name are absent, and
  stripping the signature leaves ONLY the fixed template.
- Blade unfurl `GET /daily/{date}` (`DailyShareController` + `daily/show.blade`
  + route): OG card (title Incident #N, spoiler-free `og:image` = the pipeline
  PNG on the content CDN), past-date banner, future/unpublished/malformed/
  impossible dates 404. Closes WS-15's sitemap /daily 404s. The shared landing
  layout gained a backward-compatible `@yield('og-image', <landing default>)`.

Commits (worktree branch): 14be5d2, fe47b5c, 0327330(WIP), 3d93e92 (offline
race + test types + lint), and this session's Blade-unfurl + STATUS commit.

## Remaining
- Lead integration: move the 2 proposed keys into COPY.md + ADR; regenerate
  strings; rebuild the landing artifact (catalog change stales it).
- Independent adversarial verification (deferred: session-limit blocked
  subagents; lead completed + gate-verified. A post-reset verifier pass is
  optional — the fairness-critical share test is green).

## Blockers
- None. (og:image resolves once WS-05 content is published to the CDN/R2 —
  owner provisioning, tracked in WS-16.)

## Decisions made
1. Blade `/daily/{date}` serves crawlers/first-paint; the human "boot the SPA
   onto this board" hand-off is the WS-16/17 shell-serving seam (mirrors WS-15's
   /hub redirect). CTA lands on /daily until then.
2. `og:image` points at the content CDN origin (host of
   `content.cdn_url_template`) + `/og/{puzzle_id}.png` — tracks whatever bucket
   the boards are served from; the PNG is the pipeline's spoiler-free card.
3. Shared landing layout `og:image` became a `@yield` with the landing value as
   default — backward-compatible; landing OG tests unchanged/green.
4. Offline stage transition uses an `onlineRef` so a late getDaily resolution
   reads live connectivity (jsdom keeps navigator.onLine true; the event-driven
   state is the only truth). Fixes the pre-existing app.test.tsx offline test.
5. 2 proposed keys: `share.action` ("Share the burn signature"), `daily.retry`
   ("Try the dispatch again") — quarantined for the lead ADR.

## Files touched
- apps/web/src/daily/** (new feature dir, incl. tests)
- apps/web/src/routes/DailyPage.tsx, apps/web/src/strings/{index,proposed}.ts
- api/app/Http/Controllers/DailyShareController.php (new)
- api/resources/views/daily/show.blade.php (new)
- api/resources/views/landing/layout.blade.php (og-image yield)
- api/routes/web.php (daily.share route)
- api/tests/Feature/Daily/DailyShareTest.php (new, 7 tests)

## Gates
- web: typecheck ✓, lint ✓, 377 tests ✓ (coverage 85.68% lines), budget:check ✓
- PHP: 305 tests / 3614 assertions ✓, pint ✓, phpstan L9 ✓, hygiene ✓
- budget:landing FAILS on the catalog change — lead-owned (rebuilt at
  integration).

## Resume instructions
Merged + integrated by the lead. If reopening: run `pnpm --filter @burnfront/web
test` and `php artisan test tests/Feature/Daily`. e2e (fresh user solves today's
daily; unfurl; CDN-down fallback; offline replay; streak timezone edge) is
handed to WS-17 — see the brief acceptance list.
