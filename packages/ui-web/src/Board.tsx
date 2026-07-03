/**
 * <Board> — renders a game-core PlaySession and feeds gestures back into it.
 *
 * Input contract (brief + reference/index.html):
 * - tap/click cycles marks; state is applied synchronously in the discrete
 *   event (input-to-paint < 50ms; no transition on the swap, 80ms settle
 *   animation after — styles.tsx).
 * - drag-paint: the anchor's post-cycle mark paints every entered cell; the
 *   whole stroke is ONE undo group (game-core stroke API).
 * - long-press (touch) = reverse cycle; right-click = dot toggle.
 * - full keyboard: arrows move a roving tabindex, Space/Enter = cycle,
 *   X = break toggle, . = dot toggle. Every pointer-only gesture has a
 *   keyboard equivalent (no hold-to-reveal-only interactions).
 * - spark/clue cells are inert (game-core guards; aria-disabled here).
 * - aria-live region announces the acted cell per COPY.md a11y keys.
 */
import { useEffect, useMemo, useReducer, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent, MouseEvent, PointerEvent, ReactElement } from 'react';
import type { BurnResult } from '@burnfront/engine';
import type { PlaySession } from '@burnfront/game-core';
import { cellName, formatString } from './strings';
import type { BoardStringKey, StringsFor } from './strings';

/** Touch long-press threshold; not a design token yet (see STATUS.md). */
export const LONG_PRESS_MS = 500;

export interface BoardProps {
  readonly session: PlaySession;
  /** COPY.md a11y strings (keyed-strings module lands in WS-09). */
  readonly strings: StringsFor<BoardStringKey>;
  /** Accessible name for the grid (proposed COPY key `a11y.board`). */
  readonly label: string;
  /** Locks all input (e.g. while the burn replay runs). */
  readonly disabled?: boolean;
  readonly onChange?: () => void;
  /** Fires whenever all breaks are down (valid or not) — caller decides. */
  readonly onComplete?: (result: BurnResult) => void;
}

interface Gesture {
  readonly anchor: number;
  stroking: boolean;
  longPressFired: boolean;
  timer: number | null;
}

export function Board({
  session,
  strings,
  label,
  disabled = false,
  onChange,
  onComplete,
}: BoardProps): ReactElement {
  const { rows, cols } = session.board;
  const [, bump] = useReducer((n: number) => n + 1, 0);
  const [announcement, setAnnouncement] = useState('');
  const [focusIndex, setFocusIndex] = useState(0);
  const gestureRef = useRef<Gesture | null>(null);
  const cellRefs = useRef<(HTMLButtonElement | null)[]>([]);

  const clueByIndex = useMemo(() => {
    const map = new Map<number, number>();
    for (const clue of session.board.clues) map.set(clue.r * cols + clue.c, clue.m);
    return map;
  }, [session.board, cols]);
  const sparkIndex = session.board.spark.r * cols + session.board.spark.c;

  function labelFor(index: number): string {
    const cell = cellName(index, cols);
    if (index === sparkIndex) return formatString(strings['a11y.cell.spark'], { cell });
    const clueMinute = clueByIndex.get(index);
    if (clueMinute !== undefined) {
      return formatString(strings['a11y.cell.clue'], { cell, m: clueMinute });
    }
    const mark = session.markAt(index);
    const key: BoardStringKey =
      mark === 'break' ? 'a11y.cell.break' : mark === 'dot' ? 'a11y.cell.dot' : 'a11y.cell.empty';
    return formatString(strings[key], { cell });
  }

  function afterInput(index: number): void {
    setAnnouncement(labelFor(index));
    bump();
    onChange?.();
    const result = session.completion();
    if (result !== null) onComplete?.(result);
  }

  function clearTimer(gesture: Gesture): void {
    if (gesture.timer !== null) {
      window.clearTimeout(gesture.timer);
      gesture.timer = null;
    }
  }

  function finishGesture(): void {
    const gesture = gestureRef.current;
    if (gesture === null) return;
    clearTimer(gesture);
    if (!gesture.stroking && !gesture.longPressFired) {
      // touch tap: forward cycle on release
      if (session.tap(gesture.anchor)) afterInput(gesture.anchor);
    } else if (gesture.stroking) {
      session.strokeEnd();
      bump();
      onChange?.();
    }
    gestureRef.current = null;
  }

  const finishRef = useRef(finishGesture);
  finishRef.current = finishGesture;
  useEffect(() => {
    const onUp = (): void => {
      finishRef.current();
    };
    window.addEventListener('pointerup', onUp);
    window.addEventListener('pointercancel', onUp);
    return () => {
      window.removeEventListener('pointerup', onUp);
      window.removeEventListener('pointercancel', onUp);
    };
  }, []);

  function handlePointerDown(index: number, event: PointerEvent<HTMLButtonElement>): void {
    if (disabled || event.button !== 0) return;
    try {
      event.currentTarget.releasePointerCapture(event.pointerId);
    } catch {
      /* pointer capture is best-effort (absent in test DOMs) */
    }
    finishRef.current();
    if (event.pointerType === 'touch') {
      // Defer: tap paints on release, movement starts a stroke, holding
      // LONG_PRESS_MS reverse-cycles instead.
      const gesture: Gesture = {
        anchor: index,
        stroking: false,
        longPressFired: false,
        timer: null,
      };
      gesture.timer = window.setTimeout(() => {
        gesture.timer = null;
        gesture.longPressFired = true;
        if (session.tapReverse(index)) afterInput(index);
      }, LONG_PRESS_MS);
      gestureRef.current = gesture;
      return;
    }
    // Mouse/pen: paint immediately (input-to-paint budget), stroke stays
    // open for drag-painting until pointerup.
    session.strokeBegin(index);
    gestureRef.current = { anchor: index, stroking: true, longPressFired: false, timer: null };
    afterInput(index);
  }

  function handlePointerEnter(index: number): void {
    const gesture = gestureRef.current;
    if (gesture === null || disabled) return;
    if (gesture.longPressFired) return;
    if (!gesture.stroking) {
      // Touch drag started: open the stroke at the anchor now.
      clearTimer(gesture);
      gesture.stroking = true;
      session.strokeBegin(gesture.anchor);
      afterInput(gesture.anchor);
    }
    if (index !== gesture.anchor) {
      session.strokeEnter(index);
      afterInput(index);
    }
  }

  function handleContextMenu(index: number, event: MouseEvent<HTMLButtonElement>): void {
    event.preventDefault();
    if (disabled || session.isLocked(index)) return;
    // Dot toggle: empty -> dot, break -> dot, dot -> empty. Keyboard
    // equivalent: the "." key (handleKeyDown).
    const mark = session.markAt(index);
    const changed = mark === 'empty' ? session.tapReverse(index) : session.tap(index);
    if (changed) afterInput(index);
  }

  function moveFocus(from: number, dRow: number, dCol: number): void {
    const r = Math.floor(from / cols) + dRow;
    const c = (from % cols) + dCol;
    if (r < 0 || r >= rows || c < 0 || c >= cols) return;
    const target = r * cols + c;
    setFocusIndex(target);
    cellRefs.current[target]?.focus();
  }

  function handleKeyDown(index: number, event: KeyboardEvent<HTMLButtonElement>): void {
    switch (event.key) {
      case 'ArrowRight':
        event.preventDefault();
        moveFocus(index, 0, 1);
        return;
      case 'ArrowLeft':
        event.preventDefault();
        moveFocus(index, 0, -1);
        return;
      case 'ArrowDown':
        event.preventDefault();
        moveFocus(index, 1, 0);
        return;
      case 'ArrowUp':
        event.preventDefault();
        moveFocus(index, -1, 0);
        return;
      default:
        break;
    }
    if (disabled) return;
    if (event.key === ' ' || event.key === 'Enter') {
      event.preventDefault();
      if (session.tap(index)) afterInput(index);
      return;
    }
    if (event.key === 'x' || event.key === 'X') {
      event.preventDefault();
      if (session.isLocked(index)) return;
      // Break toggle: empty -> break, dot -> break, break -> empty.
      const mark = session.markAt(index);
      const changed = mark === 'empty' ? session.tap(index) : session.tapReverse(index);
      if (changed) afterInput(index);
      return;
    }
    if (event.key === '.') {
      event.preventDefault();
      if (session.isLocked(index)) return;
      const mark = session.markAt(index);
      const changed = mark === 'empty' ? session.tapReverse(index) : session.tap(index);
      if (changed) afterInput(index);
    }
  }

  const rowElements = [];
  for (let r = 0; r < rows; r++) {
    const cells = [];
    for (let c = 0; c < cols; c++) {
      const index = r * cols + c;
      const isSpark = index === sparkIndex;
      const clueMinute = clueByIndex.get(index);
      const fixed = isSpark || clueMinute !== undefined;
      const mark = session.markAt(index);
      const classes = ['bf-cell'];
      if (fixed) classes.push('bf-cell--fixed');
      if (isSpark) classes.push('bf-cell--spark');
      if (clueMinute !== undefined) classes.push('bf-cell--clue');
      if (mark === 'break') classes.push('bf-cell--break');
      if (mark === 'dot') classes.push('bf-cell--dot');
      cells.push(
        <button
          key={index}
          type="button"
          role="gridcell"
          className={classes.join(' ')}
          tabIndex={index === focusIndex ? 0 : -1}
          aria-label={labelFor(index)}
          aria-disabled={fixed || disabled ? true : undefined}
          data-cell={cellName(index, cols)}
          ref={(el) => {
            cellRefs.current[index] = el;
          }}
          onPointerDown={(event) => {
            handlePointerDown(index, event);
          }}
          onPointerEnter={() => {
            handlePointerEnter(index);
          }}
          onContextMenu={(event) => {
            handleContextMenu(index, event);
          }}
          onKeyDown={(event) => {
            handleKeyDown(index, event);
          }}
          onFocus={() => {
            setFocusIndex(index);
          }}
        >
          <span className="bf-cell__glyph" aria-hidden="true">
            {isSpark ? '★' : clueMinute !== undefined ? clueMinute : ''}
          </span>
        </button>,
      );
    }
    rowElements.push(
      <div key={r} role="row" className="bf-board__row">
        {cells}
      </div>,
    );
  }

  return (
    <div>
      <div
        role="grid"
        aria-label={label}
        className="bf-board"
        style={{ '--bf-cols': cols } as CSSProperties}
      >
        {rowElements}
      </div>
      <div className="bf-visually-hidden" role="status" aria-live="polite">
        {announcement}
      </div>
    </div>
  );
}
