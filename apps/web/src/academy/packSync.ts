/**
 * Pack-solve sync seam for signed-in crews (brief scope note).
 *
 * Academy practice contains are the sanctioned server sync path: a mode=pack
 * SolveSubmission (openapi submitSolve enum is daily|pack|endless; puzzle_id
 * REQUIRED for pack; deduction_steps PROHIBITED for pack, ADR-0020). Academy
 * boards are ALWAYS unrated (RATING.md §3), so this records the solve without
 * touching the Fire Rating. Local completion (progress.ts) is the source of
 * truth for the hub badge regardless — this is best-effort record sync, run
 * fire-and-forget and never blocking the lesson flow.
 *
 * Everything goes through the generated api-client (CLAUDE.md rule 2); no
 * hand-written fetch. Verified with the WS-11 mockApi transport only — we
 * cannot run Laravel here. SEAM GAP (documented in STATUS): sync only lands if
 * WS-07 has the academy pack imported (`content:import`); until then /solves
 * answers 422 board_unknown, which we treat as `unavailable` (a no-op).
 */
import type { ApiClient, components } from '@burnfront/api-client';
import type { Rng } from '@burnfront/engine';
import { uuidV7 } from '@burnfront/game-core';
import type { Clock } from '../state/clock';

export interface PackSolveInput {
  readonly puzzleId: string;
  /** Row-major bit string, '1' = firebreak (engine shadingToBits). */
  readonly shaded: string;
  readonly clientMs: number;
  /** Epoch ms the session first started (SolveSubmission.started_at). */
  readonly startedAtMs: number;
  readonly undoCount: number;
  readonly hints: { readonly s1: number; readonly s2: number; readonly s3: number };
}

export type PackSyncOutcome =
  | 'synced' // 201 — first acceptance
  | 'duplicate' // 200 — idempotent replay
  | 'unauthenticated' // 401 — session lapsed; guests never call this
  | 'unavailable'; // 422/429/5xx/transport — treated as a no-op (see seam gap)

function packBody(input: PackSolveInput): components['schemas']['SolveSubmission'] {
  return {
    mode: 'pack',
    puzzle_id: input.puzzleId,
    shaded: input.shaded,
    client_ms: Math.min(86_400_000, Math.max(0, Math.round(input.clientMs))),
    started_at: new Date(input.startedAtMs).toISOString(),
    hints: { ...input.hints },
    undo_count: Math.min(100_000, Math.max(0, input.undoCount)),
    // deduction_steps is PROHIBITED for pack; replay is optional and omitted.
  };
}

export interface PackSync {
  submit(input: PackSolveInput): Promise<PackSyncOutcome>;
}

export function createPackSync(client: ApiClient, rng: Rng, clock: Clock): PackSync {
  return {
    async submit(input) {
      const key = uuidV7(clock.now(), rng);
      try {
        const result = await client.post('/solves', {
          header: { 'Idempotency-Key': key },
          body: packBody(input),
        });
        if (result.status === 201) return 'synced';
        if (result.status === 200) return 'duplicate';
        if (result.status === 401) return 'unauthenticated';
        return 'unavailable';
      } catch {
        return 'unavailable';
      }
    },
  };
}
</content>
