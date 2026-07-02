# ADR-0006: Endless solves are rated at reduced weight

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
With daily-only rating, Glicko-2 gets one game per day — calibration takes 10 days and the
progress loop dies. But endless boards are client-generated: the server cannot verify
uniqueness or fairness certificates.

## Decision
Endless solves are rated at reduced weight (exact weight + fixtures in
`contracts/RATING.md`). The server re-validates the submitted `endless_spec` with the PHP
BFS — solution validity is checkable server-side, and uniqueness is unnecessary for rating
a solve. Board rating for endless boards derives from generation parameters. Academy boards
unrated; stage-3 coach hint makes any solve unrated (stated on the button).

## Consequences
The rating loop engages in the first session. A cheater generating trivial endless boards
gains little (reduced weight, param-derived board rating); anomaly detection data is
captured for later enforcement.
