/**
 * Component stylesheet. Every color is a `var(--bf-*)` custom property from
 * tokens.ts (no raw hex — tripwire-tested); durations come from the motion
 * tokens. Visuals port the frozen prototype (reference/index.html).
 *
 * Mark swaps are INSTANT (no background transition — input-to-paint < 50ms);
 * the 80ms settle runs AFTER the swap as an animation (tokens.motion
 * cellSettleMs). prefers-reduced-motion kills all animation.
 */
import type { ReactElement } from 'react';
import { tokensCssText } from './tokens';

export const uiWebCss = `
.bf-visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

/* ---------- board ---------- */
.bf-board {
  display: grid;
  grid-template-columns: repeat(var(--bf-cols), 1fr);
  gap: var(--bf-space-board-gap);
  width: min(92vw, var(--bf-space-board-max));
  touch-action: manipulation;
}
.bf-board__row { display: contents; }
.bf-cell {
  appearance: none;
  padding: 0;
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-cell);
  background: var(--bf-color-char);
  aspect-ratio: 1;
  cursor: pointer;
  position: relative;
  color: var(--bf-color-paper);
  font-family: var(--bf-font-display);
  font-size: min(calc(84vw / var(--bf-cols) * 0.44), calc(var(--bf-space-board-max) / var(--bf-cols) * 0.44));
  line-height: 1;
  transition: border-color 200ms ease; /* never background: paint is instant */
  touch-action: none; /* drag-paint owns the gesture */
}
@media (hover: hover) {
  .bf-cell:not(.bf-cell--fixed):hover { border-color: var(--bf-color-ash-dim); }
}
.bf-cell:focus-visible {
  outline: 2px solid var(--bf-color-flame);
  outline-offset: 1px;
  z-index: 1;
}
.bf-cell--fixed { cursor: default; }
.bf-cell--spark {
  color: var(--bf-color-flame);
  background: var(--bf-color-char2);
}
.bf-cell--clue { background: var(--bf-color-char2); }
.bf-cell--break {
  background: repeating-linear-gradient(
    135deg,
    var(--bf-color-break-hatch-light) 0 3px,
    var(--bf-color-break-hatch-dark) 3px 9px
  );
  border-color: var(--bf-color-break-border);
  animation: bf-settle var(--bf-motion-cell-settle) ease-out;
}
.bf-cell--dot { animation: bf-settle var(--bf-motion-cell-settle) ease-out; }
.bf-cell--dot::after {
  content: '';
  position: absolute;
  inset: 0;
  margin: auto;
  width: 12%;
  height: 12%;
  border-radius: 50%;
  background: var(--bf-color-ash);
  opacity: 0.9;
}
.bf-cell__glyph {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
@keyframes bf-settle {
  from { transform: scale(0.92); }
  to { transform: scale(1); }
}

/* ---------- burn replay ---------- */
.bf-cell--burn {
  background: var(--bf-burn-bg, var(--bf-color-ember));
  border-color: transparent;
  color: var(--bf-color-soot);
}
.bf-cell--burn.bf-cell--spark { color: var(--bf-color-spark-on-burn); }
.bf-cell--igniting {
  /* white-hot flash -> settles onto the burnRamp color */
  animation: bf-ignite var(--bf-motion-replay-flash) ease-out;
}
@keyframes bf-ignite {
  from { background-color: var(--bf-color-paper); }
  to { background-color: var(--bf-burn-bg, var(--bf-color-ember)); }
}
.bf-cell--stamp .bf-cell__glyph {
  animation: bf-stamp var(--bf-motion-contained-stamp) ease-out;
}
@keyframes bf-stamp {
  0% { transform: scale(1); }
  55% { transform: scale(1.35); }
  100% { transform: scale(1); }
}
.bf-replay { display: flex; flex-direction: column; gap: var(--bf-space-3); }
.bf-replay__controls {
  display: flex;
  gap: var(--bf-space-2);
  align-items: center;
  min-height: 44px;
}
.bf-replay__stamp {
  font-family: var(--bf-font-display);
  font-size: 30px;
  letter-spacing: 0.05em;
  color: var(--bf-color-flame);
  line-height: 1;
  animation: bf-stamp var(--bf-motion-contained-stamp) ease-out;
}
.bf-replay--reduced .bf-cell--igniting,
.bf-replay--reduced .bf-cell--stamp .bf-cell__glyph,
.bf-replay--reduced .bf-replay__stamp {
  animation: none;
}

/* ---------- HUD ---------- */
.bf-chip {
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-control);
  padding: var(--bf-space-2) var(--bf-space-3);
  font-size: 12px;
  font-variant-numeric: tabular-nums;
  color: var(--bf-color-ash);
  display: inline-flex;
  gap: 7px;
  align-items: baseline;
  white-space: nowrap;
  font-family: var(--bf-font-body);
}
.bf-chip__value { color: var(--bf-color-paper); font-weight: 600; }
.bf-chip--over .bf-chip__value { color: var(--bf-color-danger); }
.bf-clue-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  padding: var(--bf-space-1) var(--bf-space-2);
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-control);
  background: var(--bf-color-char2);
  color: var(--bf-color-paper);
  font-family: var(--bf-font-display);
  font-variant-numeric: tabular-nums;
}
.bf-clue-pill--hit {
  border-color: var(--bf-color-ember-deep);
  color: var(--bf-color-ember);
}

/* ---------- controls ---------- */
.bf-button {
  appearance: none;
  background: none;
  cursor: pointer;
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-control);
  padding: var(--bf-space-2) 14px;
  color: var(--bf-color-ash);
  font-family: var(--bf-font-body);
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
  letter-spacing: 0.09em;
  text-transform: uppercase;
}
.bf-button:hover { border-color: var(--bf-color-ember); color: var(--bf-color-paper); }
.bf-button:disabled { opacity: 0.35; cursor: default; }
.bf-button:focus-visible { outline: 2px solid var(--bf-color-flame); outline-offset: 2px; }

@media (prefers-reduced-motion: reduce) {
  .bf-cell,
  .bf-cell__glyph,
  .bf-replay__stamp {
    animation: none !important;
    transition: none !important;
  }
}
`;

/**
 * Injects the token variables + component styles once. Mount it once per
 * page (the fixture does; apps/web will own this from WS-09 on).
 */
export function BurnfrontStyles(): ReactElement {
  return <style>{`${tokensCssText()}\n${uiWebCss}`}</style>;
}
