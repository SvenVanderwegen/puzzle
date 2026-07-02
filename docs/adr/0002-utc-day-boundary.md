# ADR-0002: The daily day boundary is UTC

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
Three design docs proposed three boundaries (UTC / local midnight / Europe/Brussels). The
boundary decides streak logic, fetch credit, freeze rollover, and offline pre-cache.

## Decision
UTC everywhere: content calendar, streaks, `puzzle_fetches` credit, `streaks:rollover`.
The UI shows a countdown to UTC midnight. `users.timezone` is stored ONLY for the
streak-risk email send time (20:00 local).

## Consequences
One global board and no timezone streak disputes; players in UTC+12 see the flip mid-day —
the countdown copy owns that. All date columns are `date` in UTC; tests must cover the
23:59→00:01 UTC edge.
