# ADR-0014: COPY.md key additions for the board/replay surface

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-04 verifier flag)

## Context
WS-04's components need four strings COPY.md lacked; the builder correctly
quarantined them behind typed props instead of inventing contract keys.

## Decision
Add keys: a11y.board ("Terrain"), replay.watchAgain ("Watch the burn again"),
replay.nextMinute ("Next minute"), replay.previousMinute ("Previous minute").

## Consequences
COPY.md amended in this range. WS-09's keyed-strings module wires them; ui-web's
prop defaults already match verbatim.
