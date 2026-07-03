/**
 * Coach escalation state (nudge → argument → resolution) fueled by the
 * engine's deduction certificate.
 *
 * Re-deduction policy (WS-03 decision, recorded in tasks/WS-03/STATUS.md):
 * deduce() runs ONCE on the original board — the certified chain is the
 * frozen vector ordering, so it is the pedagogical order. Each hint request
 * re-reads the player's CURRENT marks and targets, in chain order:
 *   1. the first step the player's marks contradict (a break on a certified
 *      open cell, or a dot on a certified break cell) — corrections first;
 *   2. otherwise the first certified break the player has not placed.
 * Un-dotted certified-open cells are never nagged about: dots are optional
 * annotations and completion only needs the breaks.
 *
 * Rating semantics per contracts/RATING.md §3: s1 counts once, each s2 costs
 * 0.15 (floor 0.5), any s3 makes the solve unrated.
 */
import { deduce } from '@burnfront/engine';
import type { BoardSpec, DeductionStep } from '@burnfront/engine';
import type { HintCounts, Mark } from './types';

export type CoachStage = 1 | 2 | 3;

export interface CoachHint {
  readonly stage: CoachStage;
  /** Row-major index of the targeted cell. */
  readonly cellIndex: number;
  /** The certified deduction step (reason/clue/minute — the UI renders it). */
  readonly step: DeductionStep;
  /** The mark stage 3 places: 'break' for break steps, 'dot' for open steps. */
  readonly targetMark: Mark;
  /** True when this hint applied the mark (stage 3 only). */
  readonly applied: boolean;
}

export interface CoachHost {
  markAt(index: number): Mark;
  /** Stage-3 resolution goes through the marks state (undoable, logged). */
  applyMark(index: number, mark: Mark): void;
}

export class CoachState {
  private readonly board: BoardSpec;
  private readonly host: CoachHost;
  private steps: readonly DeductionStep[] | null | undefined;
  private counts: { s1: number; s2: number; s3: number };
  private lastCellIndex: number | null = null;
  private lastStage: CoachStage | null = null;

  constructor(board: BoardSpec, host: CoachHost, restoredCounts?: HintCounts) {
    this.board = board;
    this.host = host;
    this.counts = {
      s1: restoredCounts?.s1 ?? 0,
      s2: restoredCounts?.s2 ?? 0,
      s3: restoredCounts?.s3 ?? 0,
    };
  }

  hintCounts(): HintCounts {
    return { ...this.counts };
  }

  /** Any stage-3 hint makes the solve unrated (RATING.md §3; ADR-0006). */
  get unrated(): boolean {
    return this.counts.s3 > 0;
  }

  /**
   * The RATING.md §3 outcome the current counters project for a valid solve:
   * max(0.5, 1 − 0.15·min(s1,1) − 0.15·s2). Null when unrated (any s3).
   */
  projectedScore(): number | null {
    if (this.unrated) return null;
    return Math.max(0.5, 1 - 0.15 * Math.min(this.counts.s1, 1) - 0.15 * this.counts.s2);
  }

  /**
   * Request the next hint. Escalates 1 → 2 → 3 while the target cell stays
   * the same; any change of target (the player made progress) resets to
   * stage 1. Stage 3 places the certified mark via the host. Returns null —
   * and counts nothing — when the board is not deduction-solvable or no
   * target remains (all breaks correctly placed).
   */
  requestHint(): CoachHint | null {
    const step = this.nextTarget();
    if (step === null) {
      this.lastCellIndex = null;
      this.lastStage = null;
      return null;
    }
    const cellIndex = step.cell.r * this.board.cols + step.cell.c;
    const stage: CoachStage =
      this.lastCellIndex === cellIndex && this.lastStage !== null && this.lastStage < 3
        ? ((this.lastStage + 1) as CoachStage)
        : 1;
    const targetMark: Mark = step.state === 'break' ? 'break' : 'dot';
    let applied = false;
    if (stage === 3) {
      this.host.applyMark(cellIndex, targetMark);
      applied = true;
    }
    if (stage === 1) this.counts.s1 += 1;
    else if (stage === 2) this.counts.s2 += 1;
    else this.counts.s3 += 1;
    this.lastCellIndex = applied ? null : cellIndex;
    this.lastStage = applied ? null : stage;
    return { stage, cellIndex, step, targetMark, applied };
  }

  /** First contradicted step in chain order, else first missing break step. */
  private nextTarget(): DeductionStep | null {
    const steps = this.certifiedSteps();
    if (steps === null) return null;
    let firstMissingBreak: DeductionStep | null = null;
    for (const step of steps) {
      const mark = this.host.markAt(step.cell.r * this.board.cols + step.cell.c);
      if (step.state === 'break') {
        if (mark === 'dot') return step; // contradiction
        if (mark !== 'break' && firstMissingBreak === null) firstMissingBreak = step;
      } else if (mark === 'break') {
        return step; // break on a certified open cell — correct it first
      }
    }
    return firstMissingBreak;
  }

  private certifiedSteps(): readonly DeductionStep[] | null {
    if (this.steps === undefined) {
      const result = deduce(this.board);
      this.steps = result === null ? null : result.steps;
    }
    return this.steps;
  }
}
