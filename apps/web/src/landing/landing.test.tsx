/**
 * WS-15 landing hydration module — logic tests.
 * Board interaction fidelity itself is ui-web's suite; here we cover the
 * landing-specific seams: inline-JSON parsing (incl. the committed fixture),
 * the hero solve → replay + midnight-card flow, and the strip driver.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { parseBoardSpec } from './boardJson';
import { HeroApp } from './HeroApp';
import { initStrip, LOOP_HOLD_MS, minuteDelayMs } from './strip';

// vitest runs from apps/web; the committed fixture lives in api/resources.
const heroFixture = JSON.parse(
  readFileSync(join(process.cwd(), '..', '..', 'api', 'resources', 'landing', 'hero.json'), 'utf8'),
) as { board: Record<string, unknown>; solution: string };

const heroBoardJson = JSON.stringify(heroFixture.board);

describe('parseBoardSpec', () => {
  it('parses the committed hero fixture (vector gen-0014)', () => {
    const board = parseBoardSpec(heroBoardJson);
    expect(board).not.toBeNull();
    expect(board?.rows).toBe(5);
    expect(board?.cols).toBe(5);
    expect(board?.breaks).toBe(4);
    expect(board?.spark).toEqual({ r: 2, c: 2 });
    expect(board?.clues).toEqual([
      { r: 2, c: 4, m: 4 },
      { r: 3, c: 1, m: 6 },
      { r: 3, c: 3, m: 10 },
    ]);
  });

  it.each([
    ['not json', 'nope{'],
    ['not an object', '[1]'],
    ['missing geometry', '{"rows":5}'],
    ['non-integer rows', '{"rows":5.5,"cols":5,"breaks":4,"spark":{"r":0,"c":0},"clues":[]}'],
    [
      'zero breaks',
      '{"rows":5,"cols":5,"breaks":0,"spark":{"r":0,"c":0},"clues":[{"r":1,"c":1,"m":1}]}',
    ],
    [
      'bad spark',
      '{"rows":5,"cols":5,"breaks":4,"spark":{"r":"x","c":0},"clues":[{"r":1,"c":1,"m":1}]}',
    ],
    ['empty clues', '{"rows":5,"cols":5,"breaks":4,"spark":{"r":0,"c":0},"clues":[]}'],
    ['bad clue', '{"rows":5,"cols":5,"breaks":4,"spark":{"r":0,"c":0},"clues":[{"r":1,"c":1}]}'],
    ['clue not object', '{"rows":5,"cols":5,"breaks":4,"spark":{"r":0,"c":0},"clues":[7]}'],
  ])('rejects %s', (_name, text) => {
    expect(parseBoardSpec(text)).toBeNull();
  });
});

/** Mouse-style tap (paint on pointerdown, stroke closes on pointerup). */
function tap(cell: Element): void {
  fireEvent.pointerDown(cell, { button: 0 });
  fireEvent.pointerUp(cell, { button: 0 });
}

function renderHero(): HTMLElement[] {
  const board = parseBoardSpec(heroBoardJson);
  if (board === null) throw new Error('hero fixture failed to parse');
  render(<HeroApp board={board} reducedMotion={true} />);
  return screen.getAllByRole('gridcell');
}

function solutionIndices(): number[] {
  const indices: number[] = [];
  for (let i = 0; i < heroFixture.solution.length; i++) {
    if (heroFixture.solution[i] === '1') indices.push(i);
  }
  return indices;
}

describe('HeroApp', () => {
  it('renders the playable board with a breaks counter at 0', () => {
    const cells = renderHero();
    expect(cells).toHaveLength(25);
    expect(screen.getByTestId('breaks-counter')).toHaveTextContent('Breaks 0/4');
  });

  it('solving the board swaps to the replay and the midnight card', () => {
    const cells = renderHero();
    for (const index of solutionIndices()) tap(cells[index] as Element);
    expect(screen.getByTestId('burn-replay')).toBeInTheDocument();
    expect(screen.getByTestId('hero-solved-card')).toHaveTextContent(
      "That's the game. A new one drops every midnight →",
    );
    const link = screen.getByTestId('hero-solved-card').querySelector('a');
    expect(link).toHaveAttribute('href', '/daily');
  });

  it('a wrong full shading shows the play.wrong line and keeps the board', () => {
    const cells = renderHero();
    // Four empty cells that are NOT the solution (indices 0..3 are open row 0).
    for (const index of [0, 1, 2, 3]) tap(cells[index] as Element);
    expect(screen.getByTestId('hero-wrong')).toHaveTextContent(
      'the fire disagrees with the report',
    );
    expect(screen.queryByTestId('burn-replay')).not.toBeInTheDocument();
    // Any further input clears the report line.
    tap(cells[0] as Element);
    expect(screen.queryByText(/fire disagrees/)).not.toBeInTheDocument();
  });
});

interface StripDom {
  readonly root: HTMLElement;
  readonly chip: HTMLElement;
  readonly step: HTMLButtonElement;
  readonly cellAt: (minute: number) => HTMLElement;
}

function makeStrip(maxMinute: number): StripDom {
  const root = document.createElement('div');
  for (let m = 0; m <= maxMinute; m++) {
    const cell = document.createElement('div');
    cell.className = 'bf-cell bf-cell--burn';
    cell.dataset.m = String(m);
    root.appendChild(cell);
  }
  const breakCell = document.createElement('div');
  breakCell.className = 'bf-cell bf-cell--break';
  breakCell.dataset.m = '-1';
  root.appendChild(breakCell);
  const chip = document.createElement('span');
  chip.dataset.bfStripMinute = '';
  root.appendChild(chip);
  const step = document.createElement('button');
  step.dataset.bfStripStep = '';
  step.hidden = true;
  root.appendChild(step);
  document.body.appendChild(root);
  return {
    root,
    chip,
    step,
    cellAt: (minute) => root.querySelector(`[data-m="${String(minute)}"]`) as HTMLElement,
  };
}

function stubReducedMotion(matches: boolean): void {
  vi.stubGlobal(
    'matchMedia',
    (query: string) =>
      ({
        matches: query.includes('prefers-reduced-motion') ? matches : false,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
      }) as unknown as MediaQueryList,
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
  document.body.innerHTML = '';
});

describe('initStrip', () => {
  it('returns null when the markup has no burnable cells', () => {
    const root = document.createElement('div');
    expect(initStrip(root)).toBeNull();
  });

  it('animated mode loops minute by minute at the token pacing', () => {
    vi.useFakeTimers();
    stubReducedMotion(false);
    // No IntersectionObserver → the driver starts unconditionally.
    vi.stubGlobal('IntersectionObserver', undefined);
    const dom = makeStrip(10);
    const handle = initStrip(dom.root);
    expect(handle?.maxMinute).toBe(10);
    // Started at minute 0: only minute-0 cell burnt, breaks untouched.
    expect(dom.cellAt(0).classList.contains('bf-cell--burn')).toBe(true);
    expect(dom.cellAt(1).classList.contains('bf-cell--burn')).toBe(false);
    expect(dom.chip.textContent).toBe('0');
    vi.advanceTimersByTime(minuteDelayMs(1));
    expect(dom.cellAt(1).classList.contains('bf-cell--burn')).toBe(true);
    expect(dom.chip.textContent).toBe('1');
    // Step through to the final minute, then the hold, then the restart.
    for (let m = 2; m <= 10; m++) vi.advanceTimersByTime(minuteDelayMs(m));
    expect(dom.chip.textContent).toBe('10');
    vi.advanceTimersByTime(LOOP_HOLD_MS);
    expect(dom.chip.textContent).toBe('0');
    expect(dom.cellAt(10).classList.contains('bf-cell--burn')).toBe(false);
  });

  it('reduced motion pauses on the final state and steps via the button', () => {
    stubReducedMotion(true);
    const dom = makeStrip(3);
    initStrip(dom.root);
    expect(dom.step.hidden).toBe(false);
    expect(dom.chip.textContent).toBe('3');
    expect(dom.cellAt(3).classList.contains('bf-cell--burn')).toBe(true);
    dom.step.click();
    expect(dom.chip.textContent).toBe('0');
    expect(dom.cellAt(1).classList.contains('bf-cell--burn')).toBe(false);
    dom.step.click();
    expect(dom.chip.textContent).toBe('1');
    expect(dom.cellAt(1).classList.contains('bf-cell--burn')).toBe(true);
  });

  it('exposes the frozen pacing (320ms, 180ms past minute 8)', () => {
    expect(minuteDelayMs(1)).toBe(320);
    expect(minuteDelayMs(8)).toBe(320);
    expect(minuteDelayMs(9)).toBe(180);
  });
});
