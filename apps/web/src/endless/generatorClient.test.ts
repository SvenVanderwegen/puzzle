/**
 * GeneratorClient: token-based cancel/regenerate race-freedom, prefetch
 * cache semantics, worker-unavailable degradation, and the main-thread
 * budget (request() returns without doing any generation work).
 */
import { describe, expect, it, vi } from 'vitest';
import type { BoardSpec } from '@burnfront/engine';
import { GenerationCancelled, GeneratorClient, type WorkerLike } from './generatorClient';
import type { GenerateRequest, WorkerResponse } from './protocol';

const fakeBoard: BoardSpec = {
  rows: 5,
  cols: 5,
  spark: { r: 3, c: 0 },
  breaks: 4,
  clues: [{ r: 3, c: 1, m: 1 }],
};

/** Manually driven worker double: requests queue up, tests post responses. */
class FakeWorker implements WorkerLike {
  readonly requests: GenerateRequest[] = [];
  terminated = false;
  private readonly listeners: ((event: { data: unknown }) => void)[] = [];

  postMessage(message: unknown): void {
    this.requests.push(message as GenerateRequest);
  }

  addEventListener(_type: 'message', listener: (event: { data: unknown }) => void): void {
    this.listeners.push(listener);
  }

  terminate(): void {
    this.terminated = true;
  }

  emit(data: WorkerResponse | Record<string, unknown>): void {
    for (const listener of this.listeners) listener({ data });
  }

  resultFor(token: number, board: BoardSpec = fakeBoard): void {
    this.emit({ kind: 'result', token, board, deductionSteps: 17 });
  }
}

function makeClient(): { client: GeneratorClient; worker: FakeWorker } {
  const worker = new FakeWorker();
  const client = new GeneratorClient(
    () => worker,
    () => [1, 2, 3, 4],
  );
  return { client, worker };
}

describe('request/response', () => {
  it('resolves the awaited token with tier and grade attached', async () => {
    const { client, worker } = makeClient();
    const promise = client.request('crew');
    expect(worker.requests).toHaveLength(1);
    const sent = worker.requests[0];
    if (sent === undefined) throw new Error('no request sent');
    expect(sent).toMatchObject({
      kind: 'generate',
      rows: 6,
      cols: 6,
      breaks: 8,
      seed: [1, 2, 3, 4],
    });
    worker.resultFor(sent.token);
    await expect(promise).resolves.toMatchObject({
      tier: 'crew',
      board: fakeBoard,
      deductionSteps: 17,
    });
  });

  it('rejects on worker failure', async () => {
    const { client, worker } = makeClient();
    const promise = client.request('lookout');
    const sent = worker.requests[0];
    if (sent === undefined) throw new Error('no request sent');
    worker.emit({ kind: 'failure', token: sent.token, message: 'no puzzle' });
    await expect(promise).rejects.toThrow('no puzzle');
  });

  it('never settles when Workers are unavailable', async () => {
    const client = new GeneratorClient(
      () => null,
      () => [1, 2, 3, 4],
    );
    const settled = vi.fn();
    client.request('lookout').then(settled, settled);
    await new Promise((resolve) => setTimeout(resolve, 10));
    expect(settled).not.toHaveBeenCalled();
  });
});

describe('cancel/regenerate races', () => {
  it('rapid re-requests: earlier promises reject, only the last token wins', async () => {
    const { client, worker } = makeClient();
    const first = client.request('lookout');
    const second = client.request('lookout');
    const third = client.request('lookout');
    await expect(first).rejects.toBeInstanceOf(GenerationCancelled);
    await expect(second).rejects.toBeInstanceOf(GenerationCancelled);
    expect(worker.requests).toHaveLength(3);

    // The worker still answers all three, in order — stale tokens are dropped.
    for (const sent of worker.requests) worker.resultFor(sent.token);
    const board = await third;
    expect(board.tier).toBe('lookout');
  });

  it('a stale result arriving after cancel() is dropped silently', async () => {
    const { client, worker } = makeClient();
    const first = client.request('hotshot');
    client.cancel();
    await expect(first).rejects.toBeInstanceOf(GenerationCancelled);

    const sent = worker.requests[0];
    if (sent === undefined) throw new Error('no request sent');
    worker.resultFor(sent.token);

    // A fresh request is unaffected by the stale response.
    const second = client.request('hotshot');
    const fresh = worker.requests[1];
    if (fresh === undefined) throw new Error('no second request');
    expect(fresh.token).not.toBe(sent.token);
    worker.resultFor(fresh.token);
    await expect(second).resolves.toMatchObject({ tier: 'hotshot' });
  });

  it('ignores malformed and unknown-token messages', async () => {
    const { client, worker } = makeClient();
    const promise = client.request('crew');
    worker.emit({ nonsense: true });
    worker.emit({ kind: 'result', token: 999_999, board: fakeBoard, deductionSteps: 1 });
    const sent = worker.requests[0];
    if (sent === undefined) throw new Error('no request sent');
    worker.resultFor(sent.token);
    await expect(promise).resolves.toMatchObject({ tier: 'crew' });
  });
});

describe('pre-generation cache', () => {
  it('prefetch fills the cache; the next request resolves instantly from it', async () => {
    const { client, worker } = makeClient();
    client.prefetch('crew');
    expect(worker.requests).toHaveLength(1);
    const sent = worker.requests[0];
    if (sent === undefined) throw new Error('no request sent');
    worker.resultFor(sent.token);
    expect(client.hasPrefetched('crew')).toBe(true);
    expect(client.hasPrefetched('hotshot')).toBe(false);

    // No new worker round-trip: the board is already local.
    const board = await client.request('crew');
    expect(board.board).toEqual(fakeBoard);
    expect(worker.requests).toHaveLength(1);
    expect(client.hasPrefetched('crew')).toBe(false);
  });

  it('prefetch is idempotent while warm or in flight', () => {
    const { client, worker } = makeClient();
    client.prefetch('crew');
    client.prefetch('crew');
    expect(worker.requests).toHaveLength(1);
    const sent = worker.requests[0];
    if (sent === undefined) throw new Error('no request sent');
    worker.resultFor(sent.token);
    client.prefetch('crew');
    expect(worker.requests).toHaveLength(1);
  });

  it('a cached board for another tier is discarded, not served', async () => {
    const { client, worker } = makeClient();
    client.prefetch('lookout');
    const sent = worker.requests[0];
    if (sent === undefined) throw new Error('no request sent');
    worker.resultFor(sent.token);
    expect(client.hasPrefetched('lookout')).toBe(true);

    const promise = client.request('hotshot');
    expect(worker.requests).toHaveLength(2);
    const fresh = worker.requests[1];
    if (fresh === undefined) throw new Error('no second request');
    expect(fresh.rows).toBe(7);
    worker.resultFor(fresh.token);
    await expect(promise).resolves.toMatchObject({ tier: 'hotshot' });
    expect(client.hasPrefetched('lookout')).toBe(false);
  });

  it('a failed prefetch leaves the cache cold', () => {
    const { client, worker } = makeClient();
    client.prefetch('crew');
    const sent = worker.requests[0];
    if (sent === undefined) throw new Error('no request sent');
    worker.emit({ kind: 'failure', token: sent.token, message: 'gave up' });
    expect(client.hasPrefetched('crew')).toBe(false);
  });
});

describe('lifecycle and main-thread budget', () => {
  it('dispose terminates the worker and rejects the in-flight request', async () => {
    const { client, worker } = makeClient();
    const promise = client.request('lookout');
    client.dispose();
    await expect(promise).rejects.toBeInstanceOf(GenerationCancelled);
    expect(worker.terminated).toBe(true);
  });

  it('creates the worker lazily, once', () => {
    const worker = new FakeWorker();
    const factory = vi.fn(() => worker);
    const client = new GeneratorClient(factory, () => [1, 2, 3, 4]);
    expect(factory).not.toHaveBeenCalled();
    void client.request('lookout').catch(() => undefined);
    client.prefetch('crew');
    expect(factory).toHaveBeenCalledTimes(1);
  });

  it('request() itself costs the main thread < 50ms (generation is remote)', () => {
    const { client } = makeClient();
    const t0 = performance.now();
    void client.request('hotshot').catch(() => undefined);
    expect(performance.now() - t0).toBeLessThan(50);
  });
});
