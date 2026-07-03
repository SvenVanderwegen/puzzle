# ADR-0022: Landing copy governance + landing.hero.solved key

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-15 verifier)

## Context
The keyed-strings rule (COPY.md header) governs "every user-facing string in
apps/". The landing/about/rules pages are Blade-rendered marketing surface in
api/resources; WS-15 quarantined its one client-rendered string and inlined the
product-spec marketing copy in Blade.

## Decision
(1) COPY.md gains `landing.hero.solved` ("That's the game. A new one drops
every midnight →") — client-rendered by the hero module. (2) Blade marketing
copy (landing/about/rules/404 pages) is explicitly EXEMPT from the keyed-strings
module but bound by the COPY.md voice guide; it lives in Blade because it is
EN-only marketing surface that iterates independently of product UI. NL
localization of marketing pages, if ever, is a Blade translation concern, not a
catalog concern.

## Consequences
COPY.md amended in-range. The hero module reads its string from the generated
catalog at its next build; tripwire scope is unchanged (apps/web only).
