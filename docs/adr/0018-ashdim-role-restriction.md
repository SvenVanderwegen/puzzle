# ADR-0018: ashDim restricted to decorative / large-text use

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-09 builder finding)

## Context
ashDim #6e6558 measures 3.22:1 on soot and 2.94:1 on char — failing WCAG AA
(4.5:1) for body-size text. The prototype used it for small labels; carrying
that into the product would fail the a11y gates.

## Decision
ashDim's role is amended to: decorative elements, disabled states, and large
text (>=24px / 18.7px bold, where 3:1 applies) only. Body-size secondary text
uses ash. design-tokens.json role comment amended in this range; the value is
unchanged (visual identity preserved where legitimately usable).
