# ADR-0009: Frontend stack (Vite+React SPA + Blade shell) and JS budgets

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
SSR was floated for SEO/unfurls, but share-crawlers read OG tags, and Next.js/Inertia-SSR
would put Node on the server against the locked stack. Two conflicting JS budgets existed.

## Decision
Vite + React 19 SPA (TanStack Router, vite-plugin-pwa) served from Laravel `public/`;
Blade renders `/`, `/rules`, `/about`, `/daily/{date}` unfurl shells, and legal pages. OG
images pre-rendered by the pipeline. Two enforced budgets: landing ≤ 90KB gz deferred JS,
HTML ≤ 60KB gz, LCP ≤ 2.0s mobile; SPA ≤ 200KB gz initial. System font stack only.
`packages/api-client` is generated from `contracts/openapi.yaml` and never hand-edited.

## Consequences
The React knowledge transfers 1:1 to Expo in phase 2; the SPA/REST seam is exactly what
mobile consumes. Landing perf work happens in Blade, not React.
