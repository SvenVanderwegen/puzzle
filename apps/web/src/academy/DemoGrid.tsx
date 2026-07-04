/**
 * <DemoGrid> — renders one beat of a walkthrough as a read-only grid.
 *
 * A pure function of (script, beat): burn minutes come from the engine
 * (`burnTimes` over the solution), so what the learner sees can never disagree
 * with the rules. Firebreaks reveal progressively (beat.breaksShown), the wave
 * fills to beat.waveMinute, and beat.highlight outlines a route / wall / clue.
 *
 * The grid is decorative (aria-hidden): the caption and the aria-live minute
 * counter in BeatPlayer carry the same information for assistive tech, so no
 * cell needs its own label.
 */
import { useMemo, type CSSProperties, type ReactElement } from 'react';
import { burnTimes } from '@burnfront/engine';
import { burnColor } from '@burnfront/ui-web';
import { demoShading, type Beat, type DemoScript } from './beats';

interface CellModel {
  readonly state: 'open' | 'break' | 'spark' | 'clue' | 'burn';
  readonly glyph: string;
  readonly hl: 'route' | 'wall' | 'focus' | null;
  readonly burnMinute: number;
}

export function DemoGrid(props: {
  readonly script: DemoScript;
  readonly beat: Beat;
}): ReactElement {
  const { script, beat } = props;
  const { board } = script;
  const cols = board.cols;

  // Burn minutes over the full solution — the single source of truth for the
  // wave. Firebreak cells are -1 here, so they never render as burnt.
  const times = useMemo(
    () => burnTimes(board.rows, cols, board.spark, demoShading(script)),
    [board, cols, script],
  );
  const maxMinute = useMemo(() => times.reduce((max, t) => (t > max ? t : max), 0), [times]);

  const sparkIndex = board.spark.r * cols + board.spark.c;
  const clueByIndex = useMemo(() => {
    const map = new Map<number, number>();
    for (const clue of board.clues) map.set(clue.r * cols + clue.c, clue.m);
    return map;
  }, [board, cols]);

  const shownBreaks = useMemo(
    () => new Set(script.solutionBreaks.slice(0, beat.breaksShown)),
    [script.solutionBreaks, beat.breaksShown],
  );
  const highlight = useMemo(() => {
    const map = new Map<number, 'route' | 'wall' | 'focus'>();
    if (beat.highlight)
      for (const cell of beat.highlight.cells) map.set(cell, beat.highlight.style);
    return map;
  }, [beat.highlight]);

  function modelFor(index: number): CellModel {
    const hl = highlight.get(index) ?? null;
    if (index === sparkIndex) return { state: 'spark', glyph: '★', hl, burnMinute: -1 };
    const clueMinute = clueByIndex.get(index);
    if (clueMinute !== undefined) {
      return { state: 'clue', glyph: String(clueMinute), hl, burnMinute: -1 };
    }
    if (shownBreaks.has(index)) return { state: 'break', glyph: '', hl, burnMinute: -1 };
    const minute = times[index] ?? -1;
    const burnt = beat.waveMinute >= 0 && minute >= 0 && minute <= beat.waveMinute;
    if (burnt) return { state: 'burn', glyph: String(minute), hl, burnMinute: minute };
    return { state: 'open', glyph: '', hl, burnMinute: -1 };
  }

  const cells: ReactElement[] = [];
  for (let index = 0; index < board.rows * cols; index++) {
    const model = modelFor(index);
    const style =
      model.state === 'burn'
        ? ({ background: burnColor(model.burnMinute, maxMinute) } as CSSProperties)
        : undefined;
    cells.push(
      <div
        key={index}
        className="bf-demo__cell"
        data-state={model.state}
        {...(model.hl === null ? {} : { 'data-hl': model.hl })}
        {...(style === undefined ? {} : { style })}
      >
        {model.glyph}
      </div>,
    );
  }

  return (
    <div
      className="bf-demo__grid"
      aria-hidden="true"
      style={{ '--bf-demo-cols': cols } as CSSProperties}
    >
      {cells}
    </div>
  );
}
