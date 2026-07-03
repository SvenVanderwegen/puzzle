# WS-09 STATUS

## Done (session 1, 2026-07-03)

Commits: `c80b01c` (all WS-09 code + tests + this ledger) followed by a one-line
SHA-recording fixup, on branch `worktree-agent-a3d23364b4c8b7e39`, on top of `210c1d2`
(WS-04 integration).

- **packages/api-client** — `src/types.gen.ts` 100% generated from `contracts/openapi.yaml`
  by openapi-typescript (`pnpm --filter @burnfront/api-client generate`), CI-freshness gate
  `generate:check` (regenerates to a temp dir, byte-diffs — vectors pattern). `src/client.ts`
  is a thin zero-dependency native-fetch wrapper TYPE-LOCKED to the generated `paths`:
  every path/verb/param/body/response type derives from `paths`; unknown paths, wrong verbs,
  missing bodies/headers are compile errors (proven by `@ts-expect-error` type-lock tests).
  12 tests, 98.66% lines (types.gen.ts excluded from coverage per brief).
- **Keyed-strings module** (`apps/web/src/strings/`) — `strings.gen.ts` generated from
  `contracts/COPY.md` at build time (`strings:generate`; parse-free at runtime; handles
  compact multi-key bullets, `.2` sibling shorthand, bold-stripping, ICU bodies with ` · `
  preserved). All 93 COPY.md keys land, including the four ADR-0014 keys ui-web quarantined.
  CI gate `strings:check` (temp-dir regen + byte diff). `icu.ts` = ICU-lite interpolator:
  `{braces}` + the two documented plural/select cases (daily.solvedBy, share.line2), `=N` /
  `one` / `other`, `#` substitution; unknown args stay verbatim (ui-web policy).
- **apps/web SPA shell** — Vite + React + TanStack Router (all allowlisted). Routes:
  `/` (hub), `/daily/{-$date}` (optional param), `/play` (validated `?tier=` search),
  `/academy`, `/academy/$slug`, `/me`, `/settings`, `/login`, + not-found surface.
  Route-change focus management (focus moves to the page `h1` on resolved navigation, not
  on initial load) + polite aria-live announcement of the new heading. Stub pages render
  real chrome + heading + catalog strings, feature areas marked `data-ws="WS-10|11|12|14"`.
- **Hub** (product §3) — the big Play button decision table, all five states implemented in
  `src/hub/playButton.ts` against the localStorage-backed anonymous-first store
  (`src/state/localState.ts`, versioned payload, corrupt-payload fallback, injected
  storage) and unit-tested state-by-state (10 unit + 5 rendered integration tests):
  first-shift / daily-unstarted (plain + "Day n" streak-holder variant) / daily-resume with
  elapsed / endless-recommended at rating tier / endless-resume. Five lanes in order:
  Daily hero (state line, streak flame, counter placeholder), Endless (three tier.size
  chips, recommendation highlighted from rating bands, per-tier solved count), Academy
  (progress), Your record (rating/calibrating chip, Guest chip + guestNote for anonymous),
  muted Rush footer strip. UTC countdown (`hub.countdown`) with injected clock
  (`src/state/clock.ts`, ADR-0002 day math), ticking via `useCountdown`.
- **PWA** — vite-plugin-pwa generateSW precaching the shell (4 entries), workbox runtime
  INLINED (zero external requests), registration injected inline at build; offline behavior:
  chrome shows `error.offline`, daily surfaces `daily.offline`, tracked via online/offline
  events (tested).
- **Design chrome** — night-incident-map layout; ALL color via ui-web `tokensCssText()`
  `--bf-*` vars; type scale emitted from design-tokens.json as `--bf-type-*` vars; system
  font stack; `index.html` title/description/theme-color filled at build time from
  COPY.md/design-tokens.json (no literals).
- **Tripwires/tests** — 105 tests, 96.45% lines (floor 70). Raw-hex tripwire over all
  sources + index.html + vite.config.ts; literal-English-JSX tripwire (text nodes +
  aria-label/title/alt/placeholder literals) outside `src/strings/`; fetch lint guard
  (`apps/web/eslint.config.js` extends root config; `no-restricted-globals`/`properties`
  ban `fetch` in src — verified to fire on a probe file).
- **Budgets** (`budget:check` script: builds + measures + fails over budget) —
  initial JS **94.40 KB gz** (budget 200 KB; raw 303 KB); zero third-party request origins
  in dist (allowlist below); sw.js precaches index.html.
- **Lighthouse** — ran @lhci/cli with the preinstalled Chromium
  (`CHROME_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome`) against `vite preview`:
  **accessibility = 1.00 (100) on all seven shell routes** (/, /daily, /play, /academy,
  /me, /settings, /login) — gate is ≥95. First run scored 0.95 with color-contrast
  failures; fixed by using the `ash` token for secondary text (see Decisions).
- **Runtime verification** — headless Chromium `--dump-dom` against the built preview:
  hub renders (First Shift decision, countdown, all lanes), /daily, /settings, /play,
  and the past-date banner on /daily/2026-01-01 all render correct catalog strings.

Gates at end of session: `pnpm install` · `pnpm -r typecheck` · `pnpm -r lint` ·
`pnpm format:check` · `pnpm -r test` (engine 52, game-core 143, ui-web 58, api-client 12,
web 105 — all green; coverage 99.33/99.26/98.67/98.66/96.45% lines) ·
`bash scripts/hygiene.sh` · `strings:check` · `generate:check` · `budget:check` — all green.

## Remaining

- Verifier session must sign off the brief acceptance checklist (not self-signed).
- Route smoke e2e + offline-with-network-disabled e2e: the Playwright harness lives in
  `e2e/` and lands in WS-17 (e2e/README.md: "Smoke journeys land with features"). The
  route smoke + offline behavior are covered here by unit/integration tests and a manual
  headless-Chromium render; the network-disabled e2e itself is deferred to WS-17.
- "Offline solved daily replay" needs WS-10 (there is no daily board/replay surface yet);
  shell-side offline copy + precache are done.
- CI wiring of `strings:check` / `generate:check` / `budget:check` (workflow files are
  out of WS-09 scope; the package scripts are ready to be called).
- Lead: add a CODEMAP.md row for `packages/api-client` at integration (builders don't
  touch CODEMAP per WS-04 precedent).

## Blockers

None.

## Decisions made (lead audit list)

1. **api-client wrapper design — needs ruling.** The brief says the package is "generated".
   `types.gen.ts` is 100% generated and CI-diff-verified; `client.ts` is a small (~200
   line) hand-written wrapper that is TYPE-LOCKED to the generated `paths` interface
   (wrong path/verb/body = compile error), zero-dependency, native fetch (DEPENDENCIES.md
   rejects fetch wrappers as dependencies — openapi-fetch et al. are not allowlisted, so
   full generation would mean vendoring a generator template anyway). DEPENDENCIES.md's
   phrase "api-client: generated code, zero deps" is satisfied in spirit (zero deps; the
   contract surface is generated); if the lead wants literal full generation, the wrapper
   can be replaced without touching consumers (same exported surface).
2. **Contract-outside statuses throw.** The client returns a status-discriminated union of
   the statuses the openapi.yaml documents per operation; a response ≥500 (documented for
   no operation) throws `ApiError`. Server conformance is enforced by Spectator (gate 5).
3. **Proposed COPY keys** (`src/strings/proposed.ts`, quarantined like WS-04, ADR-0014
   pattern — needs COPY.md amendment or a ruling): `settings.title` ("Settings" — the page
   needs an accessible h1), `hub.endless.solved` ("{n} contained this tier" — product §3
   "boards solved this tier"), `hub.academy.progress` ("{done}/{total} lessons" — product
   §3). Also noted, NOT added: a real 404 key (NotFoundPage reuses `error.generic`).
4. **Secondary text uses the `ash` token, not `ashDim`.** ashDim (#6e6558) fails WCAG
   4.5:1 on soot (3.22) and char (2.94) — Lighthouse a11y dropped to 95 with serious axe
   contrast violations. Tokens are frozen; the ROLE assignment in the shell is mine, so
   secondary text renders `ash` and the mute comes from the label/hint type scale.
   ui-web/board is untouched. If ashDim text is wanted back, it needs a contrast-passing
   token change (ADR).
5. **Third-party-URL allowlist in `budget:check`**: `www.w3.org` (XML namespaces),
   `react.dev` (React error-decoder links inside error message strings), `bit.ly`
   (workbox console.warn text in the inlined runtime), `localhost` (TanStack Router origin
   fallback) — all grep-verified inert strings, never fetched. Anything else fails the
   build check.
6. **Header nav is minimal** (eyebrow + title-link + Guest chip): COPY.md has no nav label
   keys and the hub IS the nav (chess.com model). No strings invented.
7. **PWA manifest ships without icons** (empty `icons: []`); branded icons are a
   WS-15/WS-17 concern. Manifest name/description come from the catalog at build time.
8. **Rating→tier bands** for the endless recommendation: <1100 lookout, <1450 crew,
   ≥1450 hotshot, seed 1200 (RATING.md's Glicko seed) → new players get Crew. WS-08/WS-11
   own the real mapping; it's isolated in `src/hub/tiers.ts`.
9. **Streak-holder "Day n" label** renders only when the streak is alive (lastDailyDate ==
   UTC yesterday); n = current + 1 (today's would-be day). Stale daily progress from a
   previous date counts as unstarted.
10. **@lhci/cli added to apps/web devDependencies** (allowlisted in DEPENDENCIES.md build
    list) to run the Lighthouse gate locally/CI.

## Files touched

- `apps/web/**` (new SPA: index.html, vite/vitest configs, eslint.config.js, .gitignore,
  scripts/{generate-strings,budget-check}.mjs, src/{main,router}.tsx, src/chrome/*,
  src/hub/*, src/routes/*, src/state/*, src/strings/*, src/tripwires.test.ts,
  src/app.test.tsx, src/env.d.ts, src/test-setup.ts; removed placeholder index.ts)
- `packages/api-client/**` (package.json, tsconfig.json, vitest.config.ts,
  scripts/generate.mjs, src/{types.gen.ts,client.ts,index.ts,client.test.ts}; removed
  placeholder index.test.ts)
- `tasks/WS-09/STATUS.md` (this file)
- `pnpm-lock.yaml` (new allowlisted deps: @tanstack/react-router runtime;
  vite-plugin-pwa, openapi-typescript, @lhci/cli, vite/@vitejs/plugin-react,
  testing-library trio, happy-dom, @types/react{,-dom} dev)

## Resume instructions

WS-09 scope is complete and all gates are green. Next actor:
1. Verifier session: re-run the gates above plus
   `pnpm --filter @burnfront/web strings:check|budget:check` and
   `pnpm --filter @burnfront/api-client generate:check`; falsify the brief checklist
   (Lighthouse repro: build, `pnpm --filter @burnfront/web preview`, then
   `CHROME_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome pnpm --filter
   @burnfront/web exec lhci collect --url=http://127.0.0.1:4173/ …
   --settings.onlyCategories=accessibility --settings.chromeFlags="--no-sandbox
   --headless=new"`).
2. Lead: rule on Decisions 1 and 3 (wrapper design; three proposed COPY keys → ADR like
   0014), add the api-client CODEMAP row at integration.
3. WS-10/11/12/14 replace their `data-ws` areas; the strings module, api-client, local
   state store, clock and tier helpers are ready for them.
