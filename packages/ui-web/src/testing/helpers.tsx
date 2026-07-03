/**
 * Test-only helpers (excluded from coverage; never shipped).
 */
import { fireEvent, render, screen } from '@testing-library/react';
import { PlaySession } from '@burnfront/game-core';
import { Board } from '../Board';
import type { BoardProps } from '../Board';
import { fixtureBoard } from '../fixture/fixtureBoard';
import { boardStrings } from '../fixture/fixtureStrings';

export function makeSession(): PlaySession {
  return new PlaySession({ board: fixtureBoard, mode: 'endless' }, { now: () => 0 });
}

export interface BoardHarness {
  readonly session: PlaySession;
  readonly cells: HTMLElement[];
}

export function renderBoard(
  props?: Partial<Omit<BoardProps, 'session' | 'strings' | 'label'>>,
): BoardHarness {
  const session = makeSession();
  render(<Board session={session} strings={boardStrings} label="Terrain" {...props} />);
  return { session, cells: screen.getAllByRole('gridcell') };
}

/** Mouse-style tap: paint lands on pointerdown, stroke closes on pointerup. */
export function pointerTap(cell: Element): void {
  fireEvent.pointerDown(cell, { button: 0 });
  fireEvent.pointerUp(cell, { button: 0 });
}

/** Row-major free-cell indices of the fixture board (no spark, no clues). */
export function freeCellIndices(session: PlaySession): number[] {
  const indices: number[] = [];
  const total = session.board.rows * session.board.cols;
  for (let i = 0; i < total; i++) if (!session.isLocked(i)) indices.push(i);
  return indices;
}
