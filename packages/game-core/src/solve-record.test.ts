/**
 * Solve-record assembly against the FROZEN contract.
 *
 * The SolveSubmission schema below is HAND-EXTRACTED from
 * contracts/openapi.yaml (components.schemas: SolveSubmission, HintCounts,
 * Board, ClueDef, Position) because neither js-yaml nor ajv is on the
 * contracts/DEPENDENCIES.md allowlist. The "drift tripwire" test reads the
 * yaml as text and fails when the frozen lines this fixture mirrors change,
 * so the copy cannot silently rot.
 */
import { createHash } from 'node:crypto';
import { gunzipSync, gzipSync } from 'node:zlib';
import { describe, expect, it } from 'vitest';
import openapiYaml from '../../../contracts/openapi.yaml?raw';
import { PlaySession } from './session';
import {
  asciiToBytes,
  assembleSolveRecord,
  bytesToBase64,
  encodeReplayLog,
  uuidV7,
} from './solve-record';
import type { SolveRecordDeps, SolveRecordSource } from './solve-record';
import type { Compressor, Hasher } from './types';
import {
  demoBoard,
  demoBreakIndices,
  demoSolutionBits,
  FakeClock,
  identityCompressor,
  mulberry32,
} from './testing/fixtures';

// ---- minimal JSON-schema-subset validator (test-only) ----------------------

interface MiniSchema {
  readonly type?: string;
  readonly required?: readonly string[];
  readonly properties?: Readonly<Record<string, MiniSchema>>;
  readonly additionalProperties?: boolean;
  readonly enum?: readonly string[];
  readonly pattern?: string;
  readonly format?: string;
  readonly minimum?: number;
  readonly maximum?: number;
  readonly maxLength?: number;
  readonly minItems?: number;
  readonly maxItems?: number;
  readonly items?: MiniSchema;
  readonly prefixItems?: readonly MiniSchema[];
  readonly $ref?: string;
}

const COMPONENTS: Readonly<Record<string, MiniSchema>> = {
  Position: {
    type: 'array',
    prefixItems: [
      { type: 'integer', minimum: 0 },
      { type: 'integer', minimum: 0 },
    ],
    minItems: 2,
    maxItems: 2,
  },
  ClueDef: {
    type: 'object',
    additionalProperties: false,
    required: ['r', 'c', 'm'],
    properties: {
      r: { type: 'integer', minimum: 0 },
      c: { type: 'integer', minimum: 0 },
      m: { type: 'integer', minimum: 1 },
    },
  },
  Board: {
    type: 'object',
    additionalProperties: false,
    required: ['rows', 'cols', 'spark', 'breaks', 'clues'],
    properties: {
      rows: { type: 'integer', minimum: 3, maximum: 12 },
      cols: { type: 'integer', minimum: 3, maximum: 12 },
      spark: { $ref: '#/components/schemas/Position' },
      breaks: { type: 'integer', minimum: 1 },
      clues: { type: 'array', minItems: 1, items: { $ref: '#/components/schemas/ClueDef' } },
    },
  },
  HintCounts: {
    type: 'object',
    additionalProperties: false,
    required: ['s1', 's2', 's3'],
    properties: {
      s1: { type: 'integer', minimum: 0, maximum: 200 },
      s2: { type: 'integer', minimum: 0, maximum: 200 },
      s3: { type: 'integer', minimum: 0, maximum: 200 },
    },
  },
  SolveSubmission: {
    type: 'object',
    additionalProperties: false,
    required: ['mode', 'shaded', 'client_ms', 'started_at', 'hints', 'undo_count'],
    properties: {
      mode: { type: 'string', enum: ['daily', 'pack', 'endless'] },
      puzzle_id: { type: 'string' },
      endless_spec: { $ref: '#/components/schemas/Board' },
      shaded: { type: 'string', pattern: '^[01]+$', maxLength: 144 },
      client_ms: { type: 'integer', minimum: 0, maximum: 86400000 },
      started_at: { type: 'string', format: 'date-time' },
      hints: { $ref: '#/components/schemas/HintCounts' },
      undo_count: { type: 'integer', minimum: 0, maximum: 100000 },
      replay: { type: 'string', maxLength: 262144 },
      replay_sha256: { type: 'string', pattern: '^[0-9a-f]{64}$' },
      deduction_steps: { type: 'integer', minimum: 1 },
    },
  },
};

function resolve(schema: MiniSchema): MiniSchema {
  if (schema.$ref === undefined) return schema;
  const name = schema.$ref.replace('#/components/schemas/', '');
  const target = COMPONENTS[name];
  if (target === undefined) throw new Error(`unresolvable $ref ${schema.$ref}`);
  return target;
}

function check(value: unknown, schemaIn: MiniSchema, path: string, errors: string[]): void {
  const schema = resolve(schemaIn);
  if (schema.type === 'object') {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
      errors.push(`${path}: expected object`);
      return;
    }
    const record = value as Record<string, unknown>;
    for (const key of schema.required ?? []) {
      if (!(key in record)) errors.push(`${path}: missing required ${key}`);
    }
    for (const [key, item] of Object.entries(record)) {
      const propSchema = schema.properties?.[key];
      if (propSchema === undefined) {
        if (schema.additionalProperties === false) {
          errors.push(`${path}: additional property ${key}`);
        }
        continue;
      }
      check(item, propSchema, `${path}.${key}`, errors);
    }
    return;
  }
  if (schema.type === 'array') {
    if (!Array.isArray(value)) {
      errors.push(`${path}: expected array`);
      return;
    }
    const items = value as readonly unknown[];
    if (schema.minItems !== undefined && items.length < schema.minItems) {
      errors.push(`${path}: fewer than ${String(schema.minItems)} items`);
    }
    if (schema.maxItems !== undefined && items.length > schema.maxItems) {
      errors.push(`${path}: more than ${String(schema.maxItems)} items`);
    }
    items.forEach((item, i) => {
      const itemSchema = schema.prefixItems?.[i] ?? schema.items;
      if (itemSchema !== undefined) check(item, itemSchema, `${path}[${String(i)}]`, errors);
    });
    return;
  }
  if (schema.type === 'string') {
    if (typeof value !== 'string') {
      errors.push(`${path}: expected string`);
      return;
    }
    if (schema.enum !== undefined && !schema.enum.includes(value)) {
      errors.push(`${path}: not in enum`);
    }
    if (schema.pattern !== undefined && !new RegExp(schema.pattern).test(value)) {
      errors.push(`${path}: pattern ${schema.pattern} failed`);
    }
    if (schema.maxLength !== undefined && value.length > schema.maxLength) {
      errors.push(`${path}: longer than ${String(schema.maxLength)}`);
    }
    if (schema.format === 'date-time' && Number.isNaN(Date.parse(value))) {
      errors.push(`${path}: not a date-time`);
    }
    return;
  }
  if (schema.type === 'integer') {
    if (typeof value !== 'number' || !Number.isInteger(value)) {
      errors.push(`${path}: expected integer`);
      return;
    }
    if (schema.minimum !== undefined && value < schema.minimum) {
      errors.push(`${path}: below minimum`);
    }
    if (schema.maximum !== undefined && value > schema.maximum) {
      errors.push(`${path}: above maximum`);
    }
    return;
  }
  throw new Error(`${path}: unsupported schema`);
}

function validateSubmission(value: unknown): string[] {
  const errors: string[] = [];
  const schema = COMPONENTS.SolveSubmission;
  if (schema === undefined) throw new Error('fixture missing');
  check(value, schema, '$', errors);
  return errors;
}

// ---- deps -------------------------------------------------------------------

function base64ToBytes(b64: string): Uint8Array {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function bytesToBinaryString(bytes: Uint8Array): string {
  let out = '';
  for (const byte of bytes) out += String.fromCharCode(byte);
  return out;
}

const nodeHasher: Hasher = {
  sha256Hex: (data) => createHash('sha256').update(data).digest('hex'),
};

const gzipCompressor: Compressor = {
  compress: (data) => new Uint8Array(gzipSync(data)),
};

function deps(overrides?: Partial<SolveRecordDeps>): SolveRecordDeps {
  return {
    compressor: identityCompressor,
    hasher: nodeHasher,
    rng: mulberry32(42),
    clock: new FakeClock(1_751_500_000_000),
    ...overrides,
  };
}

function solvedDailySource(): SolveRecordSource {
  const clock = new FakeClock(1_751_500_000_000);
  const session = new PlaySession({ board: demoBoard, mode: 'daily', puzzleId: 'inc-0917' }, clock);
  session.start();
  clock.advance(83_000);
  for (const i of demoBreakIndices) session.tap(i);
  session.undo();
  session.redo();
  return session.solveRecordSource();
}

// ---- tests ------------------------------------------------------------------

describe('drift tripwire: the hand-extracted schema still matches openapi.yaml', () => {
  const yaml = openapiYaml;

  it.each([
    'required: [mode, shaded, client_ms, started_at, hints, undo_count]',
    'mode: { type: string, enum: [daily, pack, endless] }',
    "pattern: '^[01]+$'",
    'maxLength: 144',
    'client_ms: { type: integer, minimum: 0, maximum: 86400000 }',
    'undo_count: { type: integer, minimum: 0, maximum: 100000 }',
    "pattern: '^[0-9a-f]{64}$'",
    'description: SHA-256 hex over the UNCOMPRESSED replay JSON bytes (ADR-0012',
    'required: [s1, s2, s3]',
    's1: { type: integer, minimum: 0, maximum: 200 }',
    'required: [rows, cols, spark, breaks, clues]',
    'rows: { type: integer, minimum: 3, maximum: 12 }',
    'required: [r, c, m]',
    'm: { type: integer, minimum: 1 }',
    'maxLength: 262144',
  ])('openapi.yaml still contains %s', (needle) => {
    expect(yaml).toContain(needle);
  });

  it('Position is still an [r, c] prefixItems pair', () => {
    expect(yaml).toMatch(/Position:\s*\n\s*type: array\s*\n\s*prefixItems:/);
  });
});

describe('assembleSolveRecord', () => {
  it('produces a schema-valid daily submission', async () => {
    const record = await assembleSolveRecord(solvedDailySource(), deps());
    expect(validateSubmission(record.payload)).toEqual([]);
    expect(record.payload.mode).toBe('daily');
    expect(record.payload.puzzle_id).toBe('inc-0917');
    expect(record.payload.endless_spec).toBeUndefined();
    expect(record.payload.shaded).toBe(demoSolutionBits);
    expect(record.payload.client_ms).toBe(83_000);
    expect(record.payload.started_at).toBe(new Date(1_751_500_000_000).toISOString());
    expect(record.payload.hints).toEqual({ s1: 0, s2: 0, s3: 0 });
    expect(record.payload.undo_count).toBe(1);
  });

  it('produces a schema-valid endless submission with the wire board shape', async () => {
    const clock = new FakeClock(1_751_500_000_000);
    const session = new PlaySession(
      { board: demoBoard, mode: 'endless', deductionSteps: 19 },
      clock,
    );
    session.start();
    for (const i of demoBreakIndices) session.tap(i);
    const record = await assembleSolveRecord(session.solveRecordSource(), deps());
    expect(validateSubmission(record.payload)).toEqual([]);
    expect(record.payload.puzzle_id).toBeUndefined();
    expect(record.payload.deduction_steps).toBe(19);
    // Positions serialize as [r, c] ARRAYS on the wire (vectors/README.md).
    expect(record.payload.endless_spec?.spark).toEqual([3, 0]);
    expect(record.payload.endless_spec?.clues[0]).toEqual({ r: 1, c: 4, m: 8 });
  });

  it('replay decodes (identity compressor) to the exact event log JSON', async () => {
    const src = solvedDailySource();
    const record = await assembleSolveRecord(src, deps());
    const decoded = bytesToBinaryString(base64ToBytes(record.payload.replay ?? ''));
    expect(JSON.parse(decoded)).toEqual(src.events.map((e) => [...e]));
  });

  it('replay_sha256 is the digest of the UNCOMPRESSED replay JSON', async () => {
    const src = solvedDailySource();
    const record = await assembleSolveRecord(src, deps({ compressor: gzipCompressor }));
    const expected = createHash('sha256').update(encodeReplayLog(src.events)).digest('hex');
    expect(record.payload.replay_sha256).toBe(expected);
  });

  it('works with a real gzip compressor (round-trips through gunzip)', async () => {
    const src = solvedDailySource();
    const record = await assembleSolveRecord(src, deps({ compressor: gzipCompressor }));
    expect(validateSubmission(record.payload)).toEqual([]);
    const unzipped = bytesToBinaryString(gunzipSync(base64ToBytes(record.payload.replay ?? '')));
    expect(JSON.parse(unzipped)).toEqual(src.events.map((e) => [...e]));
  });

  it('clamps client_ms and undo_count to the schema bounds', async () => {
    const src = {
      ...solvedDailySource(),
      clientMs: 100 * 86_400_000,
      undoCount: 999_999,
    };
    const record = await assembleSolveRecord(src, deps());
    expect(record.payload.client_ms).toBe(86_400_000);
    expect(record.payload.undo_count).toBe(100_000);
    expect(validateSubmission(record.payload)).toEqual([]);
  });

  it('rejects a daily submission without a puzzle id', async () => {
    const src = solvedDailySource();
    const rest: SolveRecordSource = {
      mode: src.mode,
      shading: src.shading,
      clientMs: src.clientMs,
      startedAtMs: src.startedAtMs,
      hints: src.hints,
      undoCount: src.undoCount,
      events: src.events,
    };
    await expect(assembleSolveRecord(rest, deps())).rejects.toThrow(/require puzzleId/);
  });

  it('clamps hint counters to the HintCounts bound', async () => {
    const src = { ...solvedDailySource(), hints: { s1: 500, s2: 500, s3: 500 } };
    const record = await assembleSolveRecord(src, deps());
    expect(record.payload.hints).toEqual({ s1: 200, s2: 200, s3: 200 });
    expect(validateSubmission(record.payload)).toEqual([]);
  });

  it('rejects an endless submission without a spec', async () => {
    const src = { ...solvedDailySource(), mode: 'endless' as const };
    await expect(assembleSolveRecord(src, deps())).rejects.toThrow(/endlessSpec/);
  });
});

describe('codecs', () => {
  it('base64 matches the platform encoder for arbitrary bytes', () => {
    const rng = mulberry32(5);
    for (let len = 0; len <= 33; len++) {
      const bytes = new Uint8Array(len);
      for (let i = 0; i < len; i++) bytes[i] = Math.floor(rng() * 256);
      expect(bytesToBase64(bytes)).toBe(btoa(bytesToBinaryString(bytes)));
    }
  });

  it('asciiToBytes rejects non-ASCII input', () => {
    expect(() => asciiToBytes('café')).toThrow(/non-ASCII/);
    expect(Array.from(asciiToBytes('[1,2]'))).toEqual([91, 49, 44, 50, 93]);
  });

  it('uuidV7 emits well-formed, time-prefixed, deterministic UUIDs', () => {
    const ts = 1_751_500_000_000;
    const a = uuidV7(ts, mulberry32(1));
    const b = uuidV7(ts, mulberry32(1));
    const c = uuidV7(ts, mulberry32(2));
    expect(a).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(a).toBe(b);
    expect(a).not.toBe(c);
    const hexTs = ts.toString(16).padStart(12, '0');
    expect(a.replaceAll('-', '').slice(0, 12)).toBe(hexTs);
  });

  it('the assembled Idempotency-Key is a UUID v7', async () => {
    const record = await assembleSolveRecord(solvedDailySource(), deps());
    expect(record.idempotencyKey).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
  });
});
