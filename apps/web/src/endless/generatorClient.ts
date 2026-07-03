/**
 * Main-thread client for the generation worker.
 *
 * Race discipline (brief acceptance "worker cancel is race-free"): every
 * request gets a fresh token; a new request supersedes the in-flight one
 * (rejecting its promise with GenerationCancelled) and any response whose
 * token is no longer awaited is dropped. The worker is single-threaded and
 * processes requests in order, so no interleaving can resurrect a stale
 * board.
 *
 * Pre-generation: prefetch() fills a one-board cache in the background while
 * the player solves; the next request() for that tier resolves from the
 * cache instantly ("next" is instant — product §1 /play).
 */
import type { BoardSpec } from '@burnfront/engine';
import type { Tier } from '../state/localState';
import { TIER_DIALS } from './params';
import { isWorkerResponse, type GenerateRequest } from './protocol';

export interface WorkerLike {
  postMessage(message: unknown): void;
  addEventListener(type: 'message', listener: (event: { data: unknown }) => void): void;
  terminate(): void;
}

export type WorkerFactory = () => WorkerLike | null;

export class GenerationCancelled extends Error {
  constructor() {
    super('generation superseded');
    this.name = 'GenerationCancelled';
  }
}

export interface EndlessBoard {
  readonly tier: Tier;
  readonly board: BoardSpec;
  readonly deductionSteps: number;
}

interface ActiveRequest {
  readonly token: number;
  readonly tier: Tier;
  readonly resolve: (board: EndlessBoard) => void;
  readonly reject: (error: Error) => void;
}

export class GeneratorClient {
  private readonly factory: WorkerFactory;
  private readonly seedSource: () => readonly number[];
  private worker: WorkerLike | null | undefined;
  private token = 0;
  private active: ActiveRequest | null = null;
  private prefetching: { readonly token: number; readonly tier: Tier } | null = null;
  private cache: EndlessBoard | null = null;

  constructor(factory: WorkerFactory, seedSource: () => readonly number[]) {
    this.factory = factory;
    this.seedSource = seedSource;
  }

  /**
   * Request a board for the tier. Supersedes any in-flight request (its
   * promise rejects with GenerationCancelled). Resolves instantly when the
   * prefetch cache holds a board for this tier. Never settles when Workers
   * are unavailable — callers stay in their loading state.
   */
  request(tier: Tier): Promise<EndlessBoard> {
    this.cancel();
    const cached = this.cache;
    // Single-slot cache for the CURRENT tier: a request consumes a matching
    // board and invalidates a mismatched one.
    this.cache = null;
    if (cached !== null && cached.tier === tier) {
      return Promise.resolve(cached);
    }
    const worker = this.ensureWorker();
    return new Promise<EndlessBoard>((resolve, reject) => {
      if (worker === null) return;
      this.token += 1;
      this.active = { token: this.token, tier, resolve, reject };
      worker.postMessage(this.makeRequest(this.token, tier));
    });
  }

  /** Fill the one-board cache in the background (no-op when already warm). */
  prefetch(tier: Tier): void {
    if (this.cache?.tier === tier) return;
    if (this.prefetching?.tier === tier) return;
    this.cache = null;
    const worker = this.ensureWorker();
    if (worker === null) return;
    this.token += 1;
    this.prefetching = { token: this.token, tier };
    worker.postMessage(this.makeRequest(this.token, tier));
  }

  /** True when the next request(tier) will resolve without generating. */
  hasPrefetched(tier: Tier): boolean {
    return this.cache?.tier === tier;
  }

  /** Reject the in-flight request; its eventual response is dropped by token. */
  cancel(): void {
    if (this.active !== null) {
      const superseded = this.active;
      this.active = null;
      superseded.reject(new GenerationCancelled());
    }
  }

  dispose(): void {
    this.cancel();
    this.prefetching = null;
    this.cache = null;
    if (this.worker !== undefined && this.worker !== null) this.worker.terminate();
    this.worker = null;
  }

  private ensureWorker(): WorkerLike | null {
    if (this.worker === undefined) {
      this.worker = this.factory();
      this.worker?.addEventListener('message', (event) => {
        this.onMessage(event.data);
      });
    }
    return this.worker;
  }

  private makeRequest(token: number, tier: Tier): GenerateRequest {
    const dials = TIER_DIALS[tier];
    return {
      kind: 'generate',
      token,
      rows: dials.rows,
      cols: dials.cols,
      breaks: dials.breaks,
      minClues: dials.minClues,
      seed: this.seedSource(),
    };
  }

  private onMessage(data: unknown): void {
    if (!isWorkerResponse(data)) return;
    if (this.active !== null && this.active.token === data.token) {
      const request = this.active;
      this.active = null;
      if (data.kind === 'result') {
        request.resolve({
          tier: request.tier,
          board: data.board,
          deductionSteps: data.deductionSteps,
        });
      } else {
        request.reject(new Error(data.message));
      }
      return;
    }
    if (this.prefetching !== null && this.prefetching.token === data.token) {
      const { tier } = this.prefetching;
      this.prefetching = null;
      if (data.kind === 'result') {
        this.cache = { tier, board: data.board, deductionSteps: data.deductionSteps };
      }
      return;
    }
    // Stale token (cancelled/superseded request): drop silently.
  }
}
