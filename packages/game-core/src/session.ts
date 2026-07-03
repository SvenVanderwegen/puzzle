/**
 * PlaySession — the framework-agnostic orchestrator one puzzle play lives in.
 * Composes marks + undo/redo + timer + coach + the replay event log, and
 * produces snapshots (persistence) and the solve-record source (submission).
 *
 * Drag-paint semantics: strokeBegin cycles the anchor cell and fixes the
 * stroke's mark to the anchor's NEW mark; every cell entered while the stroke
 * is open gets that same mark. The whole stroke is one undo group. Strokes
 * that begin on a locked cell are inert.
 */
import type { BoardSpec, BurnResult } from '@burnfront/engine';
import { CoachState } from './coach';
import type { CoachHint } from './coach';
import { MarkHistory } from './history';
import { MarksBoard, marksFromString, marksToString } from './marks';
import { SessionTimer } from './timer';
import type { TimerState } from './timer';
import type { SolveRecordSource } from './solve-record';
import type { Clock, HintCounts, Mark, MarkChange, ReplayEvent, SolveMode } from './types';
import { MARK_CODES } from './types';

export interface SessionInit {
  readonly board: BoardSpec;
  readonly mode: SolveMode;
  /** Required for daily/pack (SolveSubmission.puzzle_id); absent for endless. */
  readonly puzzleId?: string;
  /** Endless only: the generator's certified chain length (RATING.md §4). */
  readonly deductionSteps?: number;
}

export interface SessionSnapshot {
  readonly version: 1;
  readonly mode: SolveMode;
  readonly puzzleId?: string;
  readonly deductionSteps?: number;
  readonly board: BoardSpec;
  /** One char per cell: '0' empty, '1' break, '2' dot. */
  readonly marks: string;
  readonly elapsedMs: number;
  readonly startedAtMs: number | null;
  readonly hints: HintCounts;
  readonly undoCount: number;
  readonly events: readonly ReplayEvent[];
}

export class PlaySession {
  readonly board: BoardSpec;
  readonly mode: SolveMode;
  readonly puzzleId: string | undefined;
  readonly deductionSteps: number | undefined;

  private readonly clock: Clock;
  private readonly marks: MarksBoard;
  private readonly history = new MarkHistory();
  private readonly timer: SessionTimer;
  private readonly coach: CoachState;
  private readonly events: ReplayEvent[];
  private startedAtMs: number | null;
  private strokeMark: Mark | null = null;
  private strokeGroup: MarkChange[] | null = null;

  constructor(init: SessionInit, clock: Clock, restored?: SessionSnapshot) {
    this.board = init.board;
    this.mode = init.mode;
    this.puzzleId = init.puzzleId;
    this.deductionSteps = init.deductionSteps;
    this.clock = clock;
    this.marks = new MarksBoard(
      init.board,
      restored === undefined ? undefined : marksFromString(restored.marks),
    );
    this.timer = new SessionTimer(clock, restored?.elapsedMs ?? 0);
    this.coach = new CoachState(
      init.board,
      {
        markAt: (index) => this.marks.markAt(index),
        applyMark: (index, mark) => {
          this.applyCoachMark(index, mark);
        },
      },
      restored?.hints,
    );
    this.events = restored === undefined ? [] : [...restored.events];
    this.startedAtMs = restored?.startedAtMs ?? null;
    if (restored !== undefined) this.history.restoreUndoCount(restored.undoCount);
  }

  /** Rebuild an in-progress session from a snapshot (undo stacks start fresh). */
  static restore(snapshot: SessionSnapshot, clock: Clock): PlaySession {
    const init: SessionInit = {
      board: snapshot.board,
      mode: snapshot.mode,
      ...(snapshot.puzzleId !== undefined ? { puzzleId: snapshot.puzzleId } : {}),
      ...(snapshot.deductionSteps !== undefined ? { deductionSteps: snapshot.deductionSteps } : {}),
    };
    return new PlaySession(init, clock, snapshot);
  }

  // ---- marks ---------------------------------------------------------------

  markAt(index: number): Mark {
    return this.marks.markAt(index);
  }

  isLocked(index: number): boolean {
    return this.marks.isLocked(index);
  }

  get breaksPlaced(): number {
    return this.marks.breaksPlaced;
  }

  /** Tap: cycle the cell forward (empty → break → dot → empty). */
  tap(index: number): boolean {
    this.strokeEnd();
    return this.applyGesture(this.marks.cycleForward(index));
  }

  /** Reverse tap (long-press/right-click): empty → dot → break → empty. */
  tapReverse(index: number): boolean {
    this.strokeEnd();
    return this.applyGesture(this.marks.cycleReverse(index));
  }

  strokeBegin(index: number, reverse = false): void {
    this.strokeEnd();
    this.strokeGroup = [];
    if (this.marks.isLocked(index)) {
      this.strokeMark = null; // inert stroke
      return;
    }
    this.strokeMark = reverse ? this.marks.nextReverse(index) : this.marks.nextForward(index);
    this.strokeApply(index);
  }

  /** Cell entered while dragging: paint the stroke's mark. */
  strokeEnter(index: number): void {
    if (this.strokeGroup === null || this.strokeMark === null) return;
    this.strokeApply(index);
  }

  /** Close the stroke: the accumulated changes become one undo group. */
  strokeEnd(): void {
    if (this.strokeGroup !== null && this.strokeGroup.length > 0) {
      this.history.push(this.strokeGroup);
    }
    this.strokeGroup = null;
    this.strokeMark = null;
  }

  private strokeApply(index: number): void {
    if (this.strokeGroup === null || this.strokeMark === null) return;
    const change = this.marks.set(index, this.strokeMark);
    if (change !== null) {
      this.strokeGroup.push(change);
      this.logChange(change);
    }
  }

  private applyGesture(change: MarkChange | null): boolean {
    if (change === null) return false;
    this.history.push([change]);
    this.logChange(change);
    return true;
  }

  private applyCoachMark(index: number, mark: Mark): void {
    this.strokeEnd();
    const change = this.marks.set(index, mark);
    if (change !== null) {
      this.history.push([change]);
      this.logChange(change);
    }
  }

  // ---- undo/redo -----------------------------------------------------------

  get canUndo(): boolean {
    return this.history.canUndo;
  }

  get canRedo(): boolean {
    return this.history.canRedo;
  }

  get undoCount(): number {
    return this.history.undoCount;
  }

  undo(): boolean {
    this.strokeEnd();
    const group = this.history.undo();
    if (group === null) return false;
    for (let i = group.length - 1; i >= 0; i--) {
      const change = group[i];
      if (change === undefined) continue;
      const applied = this.marks.set(change.index, change.from);
      if (applied !== null) this.logChange(applied);
    }
    return true;
  }

  redo(): boolean {
    this.strokeEnd();
    const group = this.history.redo();
    if (group === null) return false;
    for (const change of group) {
      const applied = this.marks.set(change.index, change.to);
      if (applied !== null) this.logChange(applied);
    }
    return true;
  }

  // ---- timer ---------------------------------------------------------------

  get timerState(): TimerState {
    return this.timer.state;
  }

  start(): void {
    if (this.startedAtMs === null) this.startedAtMs = this.clock.now();
    this.timer.start();
  }

  pause(): void {
    this.timer.pause();
  }

  resume(): void {
    this.timer.resume();
  }

  /** UI's document-visibility hook (auto-pause; never DOM access here). */
  setHidden(hidden: boolean): void {
    this.timer.setHidden(hidden);
  }

  elapsedMs(): number {
    return this.timer.elapsedMs();
  }

  /** Epoch ms of the first start() — SolveSubmission.started_at. */
  get startedAt(): number | null {
    return this.startedAtMs;
  }

  // ---- coach ---------------------------------------------------------------

  requestHint(): CoachHint | null {
    return this.coach.requestHint();
  }

  hintCounts(): HintCounts {
    return this.coach.hintCounts();
  }

  get unrated(): boolean {
    return this.coach.unrated;
  }

  projectedScore(): number | null {
    return this.coach.projectedScore();
  }

  // ---- completion / replay log ----------------------------------------------

  completion(): BurnResult | null {
    return this.marks.completion();
  }

  shading(): readonly boolean[] {
    return this.marks.shading();
  }

  replayEvents(): readonly ReplayEvent[] {
    return [...this.events];
  }

  private logChange(change: MarkChange): void {
    this.events.push([Math.round(this.timer.elapsedMs()), change.index, MARK_CODES[change.to]]);
  }

  /**
   * Everything assembleSolveRecord needs, read from the live session.
   * Endless sessions carry their own board as the endless_spec.
   */
  solveRecordSource(): SolveRecordSource {
    if (this.startedAtMs === null) {
      throw new Error('PlaySession: cannot build a solve record before start()');
    }
    return {
      mode: this.mode,
      ...(this.puzzleId !== undefined ? { puzzleId: this.puzzleId } : {}),
      ...(this.mode === 'endless' ? { endlessSpec: this.board } : {}),
      ...(this.deductionSteps !== undefined ? { deductionSteps: this.deductionSteps } : {}),
      shading: this.marks.shading(),
      clientMs: this.timer.elapsedMs(),
      startedAtMs: this.startedAtMs,
      hints: this.coach.hintCounts(),
      undoCount: this.history.undoCount,
      events: this.replayEvents(),
    };
  }

  // ---- persistence -----------------------------------------------------------

  snapshot(): SessionSnapshot {
    this.strokeEnd();
    return {
      version: 1,
      mode: this.mode,
      ...(this.puzzleId !== undefined ? { puzzleId: this.puzzleId } : {}),
      ...(this.deductionSteps !== undefined ? { deductionSteps: this.deductionSteps } : {}),
      board: this.board,
      marks: marksToString(this.marks.snapshotMarks()),
      elapsedMs: this.timer.elapsedMs(),
      startedAtMs: this.startedAtMs,
      hints: this.coach.hintCounts(),
      undoCount: this.history.undoCount,
      events: [...this.events],
    };
  }
}
