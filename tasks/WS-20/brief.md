# WS-20: Anonymous → account merge

Lane: B · Deps: WS-07 · Sessions: 2 (1 API + 1 web flow)

## Scope
Close the critique-#11 fabrication hole while keeping the day-3 nudge honest.
API: `POST /me/import` (idempotent; ≤ 100 items; per-item: puzzle_id/date, shaded bits,
client_ms, hints). Server re-validates every daily item with BurnValidator against known
boards; **streak credit capped at 7 days**, and only for dates whose boards were published
before the claimed solve date; merged solves stored `imported=true` → percentile- and
suspect-ineligible; rating seeded from merged solves at high RD (RATING.md §import);
endless items merge as stats only. Web: signup flow uploads local log post-consume, shows
merge summary ("12 solves, 7-day streak protected"), clears local-only flags; the three
nudges (product §1) reference the real merge behavior.

## Inputs
WS-07, `contracts/openapi.yaml` import path, `contracts/RATING.md` §import.

## Outputs
Endpoint + Domain service + web flow + tests.

## Acceptance
- [ ] Fabricated-streak attack test: 100-day claimed local streak yields ≤ 7 days credit,
      no percentile entries, high-RD rating only
- [ ] Idempotent re-import: zero duplicates (unique on user+client_solve_id)
- [ ] Invalid local solves silently dropped with per-item result codes
- [ ] E2E: guest plays 3 dailies → signs up → sees merge summary → streak = 3

## Non-goals
No cross-device merge conflicts UI (last-writer-wins documented), no endless rating credit.
