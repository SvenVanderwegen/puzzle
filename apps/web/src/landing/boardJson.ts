/**
 * Inline-board JSON parsing for the landing hero (WS-15).
 *
 * The Blade landing page inlines the hero fixture board (a certified
 * contracts/vectors/generate.v1.jsonl instance, committed as
 * api/resources/landing/hero.json by scripts/build-landing.mjs) into a
 * <script type="application/json"> tag — no fetch, no generation. This
 * parses that payload into an engine BoardSpec, strictly: a malformed
 * payload returns null and the static server-rendered board stays up.
 */
import type { BoardSpec, Clue } from '@burnfront/engine';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function asInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) ? value : null;
}

function parseClue(value: unknown): Clue | null {
  if (!isRecord(value)) return null;
  const r = asInt(value.r);
  const c = asInt(value.c);
  const m = asInt(value.m);
  if (r === null || c === null || m === null) return null;
  return { r, c, m };
}

/** Parses the inlined hero board JSON; null on any structural problem. */
export function parseBoardSpec(text: string): BoardSpec | null {
  let raw: unknown;
  try {
    raw = JSON.parse(text);
  } catch {
    return null;
  }
  if (!isRecord(raw)) return null;
  const rows = asInt(raw.rows);
  const cols = asInt(raw.cols);
  const breaks = asInt(raw.breaks);
  if (rows === null || cols === null || breaks === null) return null;
  if (rows <= 0 || cols <= 0 || breaks <= 0) return null;
  if (!isRecord(raw.spark)) return null;
  const sparkR = asInt(raw.spark.r);
  const sparkC = asInt(raw.spark.c);
  if (sparkR === null || sparkC === null) return null;
  if (!Array.isArray(raw.clues) || raw.clues.length === 0) return null;
  const clues: Clue[] = [];
  for (const entry of raw.clues) {
    const clue = parseClue(entry);
    if (clue === null) return null;
    clues.push(clue);
  }
  return { rows, cols, spark: { r: sparkR, c: sparkC }, breaks, clues };
}
