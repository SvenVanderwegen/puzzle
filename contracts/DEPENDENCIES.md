# DEPENDENCIES.md — the allowlist (FROZEN after ADR-0011)

Adding anything not listed = ADR with justification, alternatives considered, and a
supply-chain note; lead approves; CI diff-checks lockfiles against this file.
**`packages/engine` stays at zero runtime dependencies permanently** (CI-asserted).
Version pins live in lockfiles; this file governs *what*, not *which version*.

## TypeScript — runtime (ships to users)

| Package | Where | Why |
|---|---|---|
| react, react-dom | apps/web, packages/ui-web | UI runtime (ADR-0009; transfers 1:1 to Expo in phase 2) |
| @tanstack/react-router | apps/web | SPA routing |

That is the entire runtime list. game-core: zero deps (engine only). api-client:
generated code, zero deps. PWA/service-worker code is build-time generated
(vite-plugin-pwa/workbox) — workbox runtime helpers ship as generated assets, not
package imports.

## TypeScript — build & test (never shipped)

typescript · @types/react · @types/react-dom (ADR-0013) · vite · vite-plugin-pwa · @vitejs/plugin-react · vitest ·
@vitest/coverage-v8 · happy-dom · @testing-library/react ·
@testing-library/user-event · @testing-library/jest-dom · eslint · @eslint/js ·
typescript-eslint · eslint-plugin-boundaries · prettier · turbo · playwright ·
@playwright/test · @axe-core/playwright · @lhci/cli · openapi-typescript ·
@redocly/cli · dependency-cruiser

## PHP (api/) — composer

| Package | Why |
|---|---|
| laravel/framework | the framework (Laravel 12) |
| laravel/sanctum | SPA cookie sessions (ADR-0003) |
| laravel/horizon | Redis queues (rating jobs, GDPR jobs) |
| laravel/nightwatch | monitoring (ADR-0010 / WS-18) |
| league/flysystem-aws-s3-v3 | R2 storage (content import, backups, exports) |
| hotmeteor/spectator (dev) | OpenAPI response conformance in tests (gate 5) |
| pestphp/pest (dev) | test runner incl. arch() boundary tests |
| mockery/mockery (dev) | required by Laravel's test harness (ADR-0013) |
| laravel/pint (dev) | formatting |
| larastan/larastan (dev) | static analysis level 9 |

PHP extensions: pdo_pgsql, redis, sodium (Ed25519 verify — no package needed),
gmp not required.

## Python (pipeline/, reference/)

Standard library + **pynacl** (Ed25519 signing) + **pillow** (OG card rendering) +
**jsonschema** (content validation) + pytest (dev). The reference implementation
itself stays stdlib-only.

## Explicitly rejected (do not re-litigate without an ADR)

- axios / ky / any fetch wrapper — the generated api-client uses native fetch
- state libraries (redux, zustand, jotai) — game-core's state machines + React state
  suffice at this scope
- CSS frameworks (tailwind et al.) — design-tokens.json + hand CSS is the system
- moment/dayjs/luxon — native Intl + Date covers v1 (UTC-only day math)
- next / inertia / SSR runtimes — ADR-0009
- ORM/query builders beyond Eloquent — Eloquent is in laravel/framework
- Plausible/Sentry/PostHog or any third-party telemetry SDK — ADR-0008
