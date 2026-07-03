/**
 * Solve-record assembly — produces exactly the SolveSubmission body of
 * contracts/openapi.yaml (validated against the schema in tests) plus the
 * Idempotency-Key (UUID v7; doubles as client_solve_id).
 *
 * Compression and hashing are injected so game-core stays dependency-free:
 * apps/web brings gzip (CompressionStream) and WebCrypto; tests bring an
 * identity compressor and node:crypto. `replay_sha256` is computed over the
 * UNCOMPRESSED replay JSON bytes, so the server verifies after gunzip
 * (WS-03 decision, recorded in tasks/WS-03/STATUS.md).
 */
import { shadingToBits } from '@burnfront/engine';
import type { BoardSpec, Rng, Shading } from '@burnfront/engine';
import type {
  Clock,
  Compressor,
  Hasher,
  HintCounts,
  ReplayEvent,
  SolveMode,
  SolveSubmission,
} from './types';
import { toWireBoard } from './types';

/** Schema bounds from contracts/openapi.yaml (SolveSubmission, HintCounts). */
const CLIENT_MS_MAX = 86_400_000;
const UNDO_COUNT_MAX = 100_000;
const HINT_COUNT_MAX = 200;

export interface SolveRecordSource {
  readonly mode: SolveMode;
  readonly puzzleId?: string;
  readonly endlessSpec?: BoardSpec;
  /** Endless only: certified chain length (≥ 1) feeding the board prior. */
  readonly deductionSteps?: number;
  readonly shading: Shading;
  readonly clientMs: number;
  readonly startedAtMs: number;
  readonly hints: HintCounts;
  readonly undoCount: number;
  readonly events: readonly ReplayEvent[];
}

export interface SolveRecordDeps {
  readonly compressor: Compressor;
  readonly hasher: Hasher;
  /** Randomness for the Idempotency-Key (UUID v7). Injected — never Math.random. */
  readonly rng: Rng;
  /** Wall clock for the Idempotency-Key timestamp. Injected — never Date.now. */
  readonly clock: Clock;
}

export interface SolveRecord {
  readonly payload: SolveSubmission;
  /** POST /solves Idempotency-Key header value (UUID v7). */
  readonly idempotencyKey: string;
}

/** The replay log is plain JSON of [t_ms, cellIndex, mark] triples — ASCII. */
export function encodeReplayLog(events: readonly ReplayEvent[]): Uint8Array {
  return asciiToBytes(JSON.stringify(events));
}

export async function assembleSolveRecord(
  src: SolveRecordSource,
  deps: SolveRecordDeps,
): Promise<SolveRecord> {
  if (src.mode === 'endless') {
    if (src.endlessSpec === undefined) {
      throw new Error('assembleSolveRecord: endless solves require endlessSpec');
    }
  } else if (src.puzzleId === undefined) {
    throw new Error(`assembleSolveRecord: ${src.mode} solves require puzzleId`);
  }
  const replayBytes = encodeReplayLog(src.events);
  const compressed = await deps.compressor.compress(replayBytes);
  const replaySha256 = await deps.hasher.sha256Hex(replayBytes);
  const payload: SolveSubmission = {
    mode: src.mode,
    ...(src.mode === 'endless' && src.endlessSpec !== undefined
      ? { endless_spec: toWireBoard(src.endlessSpec) }
      : {}),
    ...(src.mode !== 'endless' && src.puzzleId !== undefined ? { puzzle_id: src.puzzleId } : {}),
    shaded: shadingToBits(src.shading),
    client_ms: clampInt(src.clientMs, 0, CLIENT_MS_MAX),
    started_at: new Date(src.startedAtMs).toISOString(),
    hints: {
      s1: clampInt(src.hints.s1, 0, HINT_COUNT_MAX),
      s2: clampInt(src.hints.s2, 0, HINT_COUNT_MAX),
      s3: clampInt(src.hints.s3, 0, HINT_COUNT_MAX),
    },
    undo_count: clampInt(src.undoCount, 0, UNDO_COUNT_MAX),
    replay: bytesToBase64(compressed),
    replay_sha256: replaySha256,
    ...(src.mode === 'endless' && src.deductionSteps !== undefined
      ? { deduction_steps: Math.max(1, Math.round(src.deductionSteps)) }
      : {}),
  };
  return { payload, idempotencyKey: uuidV7(deps.clock.now(), deps.rng) };
}

function clampInt(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, Math.round(value)));
}

/** ASCII-only byte encoding (the replay JSON never leaves ASCII). */
export function asciiToBytes(s: string): Uint8Array {
  const out = new Uint8Array(s.length);
  for (let i = 0; i < s.length; i++) {
    const code = s.charCodeAt(i);
    if (code > 0x7f) throw new Error('asciiToBytes: non-ASCII input');
    out[i] = code;
  }
  return out;
}

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

/** Standard base64 with padding — pure TS, no environment dependency. */
export function bytesToBase64(bytes: Uint8Array): string {
  let out = '';
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i] ?? 0;
    const b = bytes[i + 1];
    const c = bytes[i + 2];
    out += B64.charAt(a >> 2);
    out += B64.charAt(((a & 0x03) << 4) | ((b ?? 0) >> 4));
    out += b === undefined ? '=' : B64.charAt(((b & 0x0f) << 2) | ((c ?? 0) >> 6));
    out += c === undefined ? '=' : B64.charAt(c & 0x3f);
  }
  return out;
}

/**
 * UUID v7 (RFC 9562): 48-bit ms timestamp + version/variant + injected
 * randomness. Deterministic given (timestampMs, rng).
 */
export function uuidV7(timestampMs: number, rng: Rng): string {
  const bytes = new Uint8Array(16);
  const ts = Math.max(0, Math.round(timestampMs));
  for (let i = 5; i >= 0; i--) {
    bytes[i] = Math.floor(ts / 2 ** ((5 - i) * 8)) % 256;
  }
  for (let i = 6; i < 16; i++) {
    bytes[i] = Math.floor(rng() * 256) & 0xff;
  }
  bytes[6] = 0x70 | ((bytes[6] ?? 0) & 0x0f);
  bytes[8] = 0x80 | ((bytes[8] ?? 0) & 0x3f);
  let hex = '';
  for (const byte of bytes) hex += byte.toString(16).padStart(2, '0');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
