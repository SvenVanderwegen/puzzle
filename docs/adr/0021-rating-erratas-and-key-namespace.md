# ADR-0021: Rating erratas + Idempotency-Key namespace

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-08 verifier findings)

## Context
(1) The WS-08 verifier proved the failed-daily dedupe anchor (deterministic hash
of public inputs, uuid-shaped) was pre-claimable via the Idempotency-Key header,
silently skipping the s=0.25 penalty; fixed by a reserved namespace. (2) Two
contract silences surfaced: failed dailies increment ratings.games/board
attempts (calibration pacing), and reject_reason now carries the internal value
'failed_daily' beyond the BurnVerdictReason enum on synthetic bookkeeping rows.

## Decision
- Idempotency-Key MUST be RFC 9562 UUID version 7; the server rejects all other
  versions (422). Synthetic failed-daily anchors use deterministic version-8
  keys, structurally unsubmittable; replay paths never surface bookkeeping rows.
  openapi Idempotency-Key description amended in this range.
- RATING.md errata (amended in-range): a failed daily counts as a rated game
  (games += 1 user-side, attempts += 1 board-side) and feeds calibration.
- db-schema.sql comment errata (amended in-range): reject_reason holds a
  BurnVerdictReason for real submissions OR the internal marker 'failed_daily'
  on synthetic rating-anchor rows (valid=false, hidden from /me/solves,
  included in GDPR export).

## Consequences
Clients generating anything but v7 break loudly at submit (game-core emits v7).
The penalty cannot be dodged; the anchor cannot leak.
