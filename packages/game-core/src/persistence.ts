/**
 * Local persistence adapter: an injected key/value storage (localStorage-
 * shaped; game-core never touches the DOM) plus save/load of PlaySession
 * snapshots. loadSnapshot validates the parsed JSON structurally — corrupt or
 * foreign data yields null, never a throw.
 */
import type { BoardSpec } from '@burnfront/engine';
import type { SessionSnapshot } from './session';
import type { KeyValueStorage, ReplayEvent } from './types';

/** In-memory KeyValueStorage (tests, SSR-less previews, fallbacks). */
export class MemoryStorage implements KeyValueStorage {
  private readonly map = new Map<string, string>();

  get(key: string): string | null {
    return this.map.get(key) ?? null;
  }

  set(key: string, value: string): void {
    this.map.set(key, value);
  }

  remove(key: string): void {
    this.map.delete(key);
  }
}

export function saveSnapshot(
  storage: KeyValueStorage,
  key: string,
  snapshot: SessionSnapshot,
): void {
  storage.set(key, JSON.stringify(snapshot));
}

export function loadSnapshot(storage: KeyValueStorage, key: string): SessionSnapshot | null {
  const raw = storage.get(key);
  if (raw === null) return null;
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  return isSessionSnapshot(parsed) ? parsed : null;
}

export function clearSnapshot(storage: KeyValueStorage, key: string): void {
  storage.remove(key);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Array.isArray narrows unknown to any[]; keep everything unknown-typed. */
function asArray(value: unknown): readonly unknown[] | null {
  return Array.isArray(value) ? (value as readonly unknown[]) : null;
}

function isCell(value: unknown): value is { r: number; c: number } {
  return isRecord(value) && typeof value.r === 'number' && typeof value.c === 'number';
}

function isClue(value: unknown): value is { r: number; c: number; m: number } {
  return (
    isRecord(value) &&
    typeof value.r === 'number' &&
    typeof value.c === 'number' &&
    typeof value.m === 'number'
  );
}

function isBoardSpec(value: unknown): value is BoardSpec {
  if (!isRecord(value)) return false;
  const clues = asArray(value.clues);
  return (
    typeof value.rows === 'number' &&
    typeof value.cols === 'number' &&
    typeof value.breaks === 'number' &&
    isCell(value.spark) &&
    clues !== null &&
    clues.every(isClue)
  );
}

function isReplayEvent(value: unknown): value is ReplayEvent {
  const triple = asArray(value);
  return (
    triple !== null &&
    triple.length === 3 &&
    triple.every((n) => typeof n === 'number') &&
    (triple[2] === 0 || triple[2] === 1 || triple[2] === 2)
  );
}

function isHintCounts(value: unknown): value is SessionSnapshot['hints'] {
  return (
    isRecord(value) &&
    typeof value.s1 === 'number' &&
    typeof value.s2 === 'number' &&
    typeof value.s3 === 'number'
  );
}

export function isSessionSnapshot(value: unknown): value is SessionSnapshot {
  if (!isRecord(value)) return false;
  if (value.version !== 1) return false;
  if (value.mode !== 'daily' && value.mode !== 'pack' && value.mode !== 'endless') return false;
  if (value.puzzleId !== undefined && typeof value.puzzleId !== 'string') return false;
  if (value.deductionSteps !== undefined && typeof value.deductionSteps !== 'number') return false;
  if (!isBoardSpec(value.board)) return false;
  if (typeof value.marks !== 'string' || !/^[012]*$/.test(value.marks)) return false;
  if (value.marks.length !== value.board.rows * value.board.cols) return false;
  if (typeof value.elapsedMs !== 'number') return false;
  if (value.startedAtMs !== null && typeof value.startedAtMs !== 'number') return false;
  if (!isHintCounts(value.hints)) return false;
  if (typeof value.undoCount !== 'number') return false;
  const events = asArray(value.events);
  if (events === null || !events.every(isReplayEvent)) return false;
  return true;
}
