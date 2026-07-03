/**
 * Small HUD pieces: <CluePill>, <BreaksCounter>, <MinuteCounter>.
 * Dispatcher voice: numbers are facts. All copy arrives via strings props
 * (COPY.md); colors via --bf-* tokens only.
 */
import type { ReactElement } from 'react';
import { formatString } from './strings';
import type { HudStringKey, StringsFor } from './strings';

export interface CluePillProps {
  /** The clue's minute (exact arrival time). */
  readonly minute: number;
  /** True once the replay showed the fire hitting it on time. */
  readonly hit?: boolean;
  /** Accessible label (e.g. the a11y.cell.clue string, pre-formatted). */
  readonly label?: string;
}

export function CluePill({ minute, hit = false, label }: CluePillProps): ReactElement {
  return (
    <span
      className={`bf-clue-pill${hit ? ' bf-clue-pill--hit' : ''}`}
      {...(label !== undefined ? { 'aria-label': label } : {})}
    >
      {minute}
    </span>
  );
}

export interface BreaksCounterProps {
  readonly placed: number;
  readonly total: number;
  /** COPY.md `play.breaks` — "Breaks {placed}/{n}". */
  readonly strings: StringsFor<HudStringKey>;
}

export function BreaksCounter({ placed, total, strings }: BreaksCounterProps): ReactElement {
  const over = placed > total;
  return (
    <span className={`bf-chip${over ? ' bf-chip--over' : ''}`} data-testid="breaks-counter">
      <span className="bf-chip__value">
        {formatString(strings['play.breaks'], { placed, n: total })}
      </span>
    </span>
  );
}

export interface MinuteCounterProps {
  /** Current replay minute; null before the burn starts. */
  readonly minute: number | null;
}

export function MinuteCounter({ minute }: MinuteCounterProps): ReactElement {
  return (
    <span className="bf-chip" data-testid="minute-counter">
      <span className="bf-chip__value">{minute ?? '–'}</span>
    </span>
  );
}
