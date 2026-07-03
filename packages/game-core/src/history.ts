/**
 * Undo/redo history over mark changes. Unlimited depth. One entry = one
 * player gesture: a tap is a single-change group, a whole drag-stroke is one
 * group (undone/redone atomically). `undoCount` feeds the solve record.
 */
import type { MarkChange } from './types';

export class MarkHistory {
  private readonly past: MarkChange[][] = [];
  private readonly future: MarkChange[][] = [];
  private undos = 0;

  /** Record an applied group. Empty groups are ignored. Clears the redo stack. */
  push(group: readonly MarkChange[]): void {
    if (group.length === 0) return;
    this.past.push([...group]);
    this.future.length = 0;
  }

  get canUndo(): boolean {
    return this.past.length > 0;
  }

  get canRedo(): boolean {
    return this.future.length > 0;
  }

  /** Total undo() calls that undid something (SolveSubmission.undo_count). */
  get undoCount(): number {
    return this.undos;
  }

  get depth(): number {
    return this.past.length;
  }

  /**
   * Pop the newest group for the caller to revert (apply `from` in reverse
   * order). Returns null when there is nothing to undo.
   */
  undo(): readonly MarkChange[] | null {
    const group = this.past.pop();
    if (group === undefined) return null;
    this.future.push(group);
    this.undos += 1;
    return group;
  }

  /**
   * Pop the newest undone group for the caller to re-apply (apply `to` in
   * forward order). Returns null when there is nothing to redo.
   */
  redo(): readonly MarkChange[] | null {
    const group = this.future.pop();
    if (group === undefined) return null;
    this.past.push(group);
    return group;
  }

  /** Restore a persisted undo counter (history stacks are not persisted). */
  restoreUndoCount(count: number): void {
    this.undos = count;
  }
}
