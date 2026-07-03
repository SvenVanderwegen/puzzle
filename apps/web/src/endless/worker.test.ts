/**
 * Worker protocol unit tests: real engine generation driven through the
 * exported message handler (tests are the one place generation may run on
 * the main thread — boundary.test.ts pins the production rule).
 */
import { describe, expect, it } from 'vitest';
import { handleGenerateMessage } from './generator.worker';
import { TIER_DIALS, tierOfBoard } from './params';
import type { GenerateRequest, WorkerResponse } from './protocol';
import { isGenerateRequest, isWorkerResponse } from './protocol';

function request(overrides: Partial<GenerateRequest> = {}): GenerateRequest {
  return {
    kind: 'generate',
    token: 7,
    ...TIER_DIALS.lookout,
    seed: [11, 22, 33, 44],
    ...overrides,
  };
}

function collect(data: unknown): WorkerResponse[] {
  const posted: WorkerResponse[] = [];
  handleGenerateMessage(data, (response) => posted.push(response));
  return posted;
}

describe('generator worker protocol', () => {
  it('generates a lookout board with the certified deduction grade', () => {
    const [response] = collect(request());
    expect(response).toBeDefined();
    if (response === undefined || response.kind !== 'result') throw new Error('expected result');
    expect(response.token).toBe(7);
    expect(response.board.rows).toBe(5);
    expect(response.board.cols).toBe(5);
    expect(response.board.breaks).toBe(4);
    expect(response.board.clues.length).toBeGreaterThanOrEqual(TIER_DIALS.lookout.minClues);
    expect(response.deductionSteps).toBeGreaterThanOrEqual(1);
    expect(tierOfBoard(response.board)).toBe('lookout');
  });

  it('is deterministic per seed and varies across seeds', () => {
    const [a] = collect(request({ token: 1 }));
    const [b] = collect(request({ token: 2 }));
    const [c] = collect(request({ token: 3, seed: [99, 98, 97, 96] }));
    if (a?.kind !== 'result' || b?.kind !== 'result' || c?.kind !== 'result') {
      throw new Error('expected results');
    }
    expect(b.board).toEqual(a.board);
    expect(c.board).not.toEqual(a.board);
  });

  it('answers impossible dials with a token-tagged failure', () => {
    const [response] = collect(request({ token: 13, rows: 0 }));
    expect(response).toBeDefined();
    if (response === undefined || response.kind !== 'failure') throw new Error('expected failure');
    expect(response.token).toBe(13);
    expect(response.message.length).toBeGreaterThan(0);
  });

  it('ignores malformed messages', () => {
    expect(collect(null)).toEqual([]);
    expect(collect({ kind: 'generate' })).toEqual([]);
    expect(collect({ kind: 'other', token: 1 })).toEqual([]);
    expect(collect(request({ seed: ['x'] as unknown as number[] }))).toEqual([]);
  });
});

describe('protocol guards', () => {
  it('validates requests and responses', () => {
    expect(isGenerateRequest(request())).toBe(true);
    expect(isGenerateRequest({ ...request(), minClues: 'many' })).toBe(false);
    expect(isWorkerResponse({ kind: 'failure', token: 1, message: 'x' })).toBe(true);
    expect(isWorkerResponse({ kind: 'failure', token: 1 })).toBe(false);
    expect(isWorkerResponse({ kind: 'result', token: 1, board: {}, deductionSteps: 3 })).toBe(true);
    expect(isWorkerResponse({ kind: 'result', token: 1, deductionSteps: 3 })).toBe(false);
    expect(isWorkerResponse(42)).toBe(false);
  });
});
