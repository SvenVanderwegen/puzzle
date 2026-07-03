/**
 * <Board> marking flows — fixture-based DOM assertions (ADR-0010: no pixel
 * diffs). Pointer gestures, drag-paint undo grouping, long-press reverse,
 * right-click dot, locked cells, roving tabindex, announcements.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Board, LONG_PRESS_MS } from './Board';
import { fixtureBoard, fixtureBreakIndices } from './fixture/fixtureBoard';
import { boardStrings } from './fixture/fixtureStrings';
import { freeCellIndices, makeSession, pointerTap, renderBoard } from './testing/helpers';

afterEach(() => {
  vi.useRealTimers();
});

describe('tap cycle (mouse)', () => {
  it('cycles empty -> break -> dot -> empty, updating class and aria-label', () => {
    const { cells } = renderBoard();
    const cell = cells[0];
    if (cell === undefined) throw new Error('missing cell');
    expect(cell).toHaveAccessibleName('A1, empty');

    pointerTap(cell);
    expect(cell).toHaveClass('bf-cell--break');
    expect(cell).toHaveAccessibleName('A1, firebreak');

    pointerTap(cell);
    expect(cell).not.toHaveClass('bf-cell--break');
    expect(cell).toHaveClass('bf-cell--dot');
    expect(cell).toHaveAccessibleName('A1, marked clear');

    pointerTap(cell);
    expect(cell).not.toHaveClass('bf-cell--dot');
    expect(cell).toHaveAccessibleName('A1, empty');
  });

  it('paint lands on pointerdown, before pointerup', () => {
    const { cells } = renderBoard();
    const cell = cells[1];
    if (cell === undefined) throw new Error('missing cell');
    fireEvent.pointerDown(cell, { button: 0 });
    expect(cell).toHaveClass('bf-cell--break');
    fireEvent.pointerUp(cell, { button: 0 });
  });

  it('announces the acted cell through the live region (COPY a11y keys)', () => {
    const { cells } = renderBoard();
    const cell = cells[0];
    if (cell === undefined) throw new Error('missing cell');
    pointerTap(cell);
    expect(screen.getByRole('status')).toHaveTextContent('A1, firebreak');
    pointerTap(cell);
    expect(screen.getByRole('status')).toHaveTextContent('A1, marked clear');
  });

  it('each single tap is its own undo group', () => {
    const { session, cells } = renderBoard();
    const a = cells[0];
    const b = cells[1];
    if (a === undefined || b === undefined) throw new Error('missing cells');
    pointerTap(a);
    pointerTap(b);
    expect(session.markAt(0)).toBe('break');
    expect(session.markAt(1)).toBe('break');
    session.undo();
    expect(session.markAt(0)).toBe('break');
    expect(session.markAt(1)).toBe('empty');
  });
});

describe('right-click', () => {
  it('toggles the dot: empty -> dot -> empty; break -> dot', () => {
    const { cells } = renderBoard();
    const cell = cells[0];
    if (cell === undefined) throw new Error('missing cell');
    fireEvent.contextMenu(cell);
    expect(cell).toHaveClass('bf-cell--dot');
    fireEvent.contextMenu(cell);
    expect(cell).not.toHaveClass('bf-cell--dot');

    pointerTap(cell); // break
    fireEvent.contextMenu(cell);
    expect(cell).toHaveClass('bf-cell--dot');
    expect(cell).not.toHaveClass('bf-cell--break');
  });
});

describe('drag-paint', () => {
  it('paints the anchor mark across entered cells as ONE undo group', () => {
    const { session, cells } = renderBoard();
    const [a, b, c] = [cells[0], cells[1], cells[2]];
    if (a === undefined || b === undefined || c === undefined) throw new Error('missing cells');
    fireEvent.pointerDown(a, { button: 0 });
    fireEvent.pointerEnter(b);
    fireEvent.pointerEnter(c);
    fireEvent.pointerUp(c, { button: 0 });
    expect(a).toHaveClass('bf-cell--break');
    expect(b).toHaveClass('bf-cell--break');
    expect(c).toHaveClass('bf-cell--break');

    session.undo();
    expect(session.markAt(0)).toBe('empty');
    expect(session.markAt(1)).toBe('empty');
    expect(session.markAt(2)).toBe('empty');
    expect(session.canUndo).toBe(false);
  });

  it('skips locked cells crossed mid-stroke', () => {
    const { session, cells } = renderBoard();
    // row 2: K=10 free, 11 free, 12 is the "5" clue, 13 free
    const [k10, k11, clue, k13] = [cells[10], cells[11], cells[12], cells[13]];
    if (k10 === undefined || k11 === undefined || clue === undefined || k13 === undefined) {
      throw new Error('missing cells');
    }
    fireEvent.pointerDown(k10, { button: 0 });
    fireEvent.pointerEnter(k11);
    fireEvent.pointerEnter(clue);
    fireEvent.pointerEnter(k13);
    fireEvent.pointerUp(k13, { button: 0 });
    expect(session.markAt(12)).toBe('empty');
    expect(clue).not.toHaveClass('bf-cell--break');
    expect(session.markAt(10)).toBe('break');
    expect(session.markAt(11)).toBe('break');
    expect(session.markAt(13)).toBe('break');
  });

  it('a stroke anchored on a locked cell is inert', () => {
    const { session, cells } = renderBoard();
    const spark = cells[15];
    const free = cells[10];
    if (spark === undefined || free === undefined) throw new Error('missing cells');
    fireEvent.pointerDown(spark, { button: 0 });
    fireEvent.pointerEnter(free);
    fireEvent.pointerUp(free, { button: 0 });
    expect(session.breaksPlaced).toBe(0);
  });
});

describe('touch gestures', () => {
  it('touch tap cycles forward on release', () => {
    const { cells } = renderBoard();
    const cell = cells[0];
    if (cell === undefined) throw new Error('missing cell');
    fireEvent.pointerDown(cell, { button: 0, pointerType: 'touch' });
    expect(cell).not.toHaveClass('bf-cell--break'); // deferred
    fireEvent.pointerUp(cell, { button: 0, pointerType: 'touch' });
    expect(cell).toHaveClass('bf-cell--break');
  });

  it('long-press reverse-cycles (empty -> dot) and release adds nothing', () => {
    vi.useFakeTimers();
    const { session, cells } = renderBoard();
    const cell = cells[0];
    if (cell === undefined) throw new Error('missing cell');
    fireEvent.pointerDown(cell, { button: 0, pointerType: 'touch' });
    act(() => {
      vi.advanceTimersByTime(LONG_PRESS_MS);
    });
    expect(cell).toHaveClass('bf-cell--dot');
    fireEvent.pointerUp(cell, { button: 0, pointerType: 'touch' });
    expect(cell).toHaveClass('bf-cell--dot');
    expect(session.markAt(0)).toBe('dot');
  });

  it('touch drag starts a stroke and cancels the long-press', () => {
    vi.useFakeTimers();
    const { session, cells } = renderBoard();
    const a = cells[0];
    const b = cells[1];
    if (a === undefined || b === undefined) throw new Error('missing cells');
    fireEvent.pointerDown(a, { button: 0, pointerType: 'touch' });
    fireEvent.pointerEnter(b);
    act(() => {
      vi.advanceTimersByTime(LONG_PRESS_MS * 2);
    });
    fireEvent.pointerUp(b, { button: 0, pointerType: 'touch' });
    expect(session.markAt(0)).toBe('break');
    expect(session.markAt(1)).toBe('break');
    session.undo();
    expect(session.markAt(0)).toBe('empty');
    expect(session.markAt(1)).toBe('empty');
  });
});

describe('locked cells', () => {
  it('spark and clue cells are inert and marked aria-disabled', () => {
    const { session, cells } = renderBoard();
    const spark = cells[15];
    const clue = cells[12];
    if (spark === undefined || clue === undefined) throw new Error('missing cells');
    expect(spark).toHaveAttribute('aria-disabled', 'true');
    expect(clue).toHaveAttribute('aria-disabled', 'true');
    expect(spark).toHaveAccessibleName('A4, the spark');
    expect(clue).toHaveAccessibleName('C3, clue: burns at minute 5');
    pointerTap(spark);
    pointerTap(clue);
    fireEvent.contextMenu(clue);
    expect(session.breaksPlaced).toBe(0);
    expect(spark).not.toHaveClass('bf-cell--break');
    expect(clue).not.toHaveClass('bf-cell--dot');
  });
});

describe('disabled board', () => {
  it('ignores all input', () => {
    const { session, cells } = renderBoard({ disabled: true });
    const cell = cells[0];
    if (cell === undefined) throw new Error('missing cell');
    pointerTap(cell);
    fireEvent.contextMenu(cell);
    fireEvent.keyDown(cell, { key: 'x' });
    expect(session.breaksPlaced).toBe(0);
    expect(session.markAt(0)).toBe('empty');
  });
});

describe('keyboard', () => {
  it('arrows move a roving tabindex; exactly one cell is tabbable', async () => {
    const user = userEvent.setup();
    const { cells } = renderBoard();
    await user.tab();
    expect(cells[0]).toHaveFocus();
    await user.keyboard('{ArrowRight}');
    expect(cells[1]).toHaveFocus();
    await user.keyboard('{ArrowDown}');
    expect(cells[6]).toHaveFocus();
    await user.keyboard('{ArrowLeft}');
    expect(cells[5]).toHaveFocus();
    await user.keyboard('{ArrowUp}');
    expect(cells[0]).toHaveFocus();
    const tabbable = cells.filter((c) => c.tabIndex === 0);
    expect(tabbable).toHaveLength(1);
    expect(tabbable[0]).toBe(cells[0]);
  });

  it('arrows clamp at the edges', async () => {
    const user = userEvent.setup();
    const { cells } = renderBoard();
    await user.tab();
    await user.keyboard('{ArrowUp}{ArrowLeft}');
    expect(cells[0]).toHaveFocus();
  });

  it('Space cycles, X toggles break, . toggles dot', async () => {
    const user = userEvent.setup();
    const { session, cells } = renderBoard();
    const cell = cells[0];
    if (cell === undefined) throw new Error('missing cell');
    await user.tab();
    await user.keyboard(' ');
    expect(session.markAt(0)).toBe('break');
    await user.keyboard(' ');
    expect(session.markAt(0)).toBe('dot');
    await user.keyboard(' ');
    expect(session.markAt(0)).toBe('empty');

    await user.keyboard('x');
    expect(session.markAt(0)).toBe('break');
    await user.keyboard('x');
    expect(session.markAt(0)).toBe('empty');

    await user.keyboard('.');
    expect(session.markAt(0)).toBe('dot');
    await user.keyboard('.');
    expect(session.markAt(0)).toBe('empty');

    // cross-state: dot -> X -> break; break -> . -> dot
    await user.keyboard('.');
    await user.keyboard('x');
    expect(session.markAt(0)).toBe('break');
    await user.keyboard('.');
    expect(session.markAt(0)).toBe('dot');
  });
});

describe('completion', () => {
  it('reports a valid result to onComplete when the solution lands', () => {
    const session = makeSession();
    const onComplete = vi.fn();
    render(
      <Board session={session} strings={boardStrings} label="Terrain" onComplete={onComplete} />,
    );
    const cells = screen.getAllByRole('gridcell');
    for (const index of fixtureBreakIndices) {
      const cell = cells[index];
      if (cell === undefined) throw new Error('missing cell');
      pointerTap(cell);
    }
    expect(onComplete).toHaveBeenCalled();
    const result = onComplete.mock.calls.at(-1)?.[0] as { valid: boolean };
    expect(result.valid).toBe(true);
  });

  it('reports an invalid result when the wrong breaks land', () => {
    const session = makeSession();
    const onComplete = vi.fn();
    render(
      <Board session={session} strings={boardStrings} label="Terrain" onComplete={onComplete} />,
    );
    const cells = screen.getAllByRole('gridcell');
    const wrong = freeCellIndices(session).slice(0, fixtureBoard.breaks);
    for (const index of wrong) {
      const cell = cells[index];
      if (cell === undefined) throw new Error('missing cell');
      pointerTap(cell);
    }
    expect(onComplete).toHaveBeenCalled();
    const result = onComplete.mock.calls.at(-1)?.[0] as { valid: boolean };
    expect(result.valid).toBe(false);
  });
});
