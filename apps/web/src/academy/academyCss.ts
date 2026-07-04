/**
 * Academy stylesheet — the lesson index, the walkthrough demo grid and the
 * lesson-complete panel. Every color/space/motion value is a `var(--bf-*)`
 * custom property emitted by ui-web tokens.ts (appCss injects them at the shell
 * root); no raw hex (tripwires.test.ts). The demo grid mirrors the prototype's
 * `.dcell` look — walls dark, burnt cells warm, highlights outlined — but is
 * read-only. prefers-reduced-motion kills the cell transition; the BeatPlayer
 * already drops autoplay for the stepper.
 */
export const academyCss = `
.bf-academy__intro {
  color: var(--bf-color-ash);
  font-size: var(--bf-type-hint);
  margin: 0 0 var(--bf-space-4);
}

.bf-lessons { list-style: none; margin: 0; padding: 0; }

.bf-lessons__item {
  border-top: 1px solid var(--bf-color-line);
  padding: var(--bf-space-3) 0;
}

.bf-lessons__link {
  color: var(--bf-color-paper);
  display: flex;
  align-items: baseline;
  gap: var(--bf-space-2);
  text-decoration: none;
}

.bf-lessons__ord {
  color: var(--bf-color-ash);
  font-variant-numeric: tabular-nums;
}

.bf-lessons__title { font-weight: 600; }

.bf-lessons__blurb {
  color: var(--bf-color-ash);
  font-size: var(--bf-type-hint);
  margin: var(--bf-space-1) 0 0;
}

.bf-badge {
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-control);
  color: var(--bf-color-ash);
  font-size: var(--bf-type-label);
  letter-spacing: var(--bf-tracking-label);
  padding: 0 var(--bf-space-2);
  text-transform: uppercase;
}

.bf-badge--done { border-color: var(--bf-color-ember-deep); color: var(--bf-color-ember); }

.bf-badge--certified {
  background: var(--bf-color-ember);
  border-color: var(--bf-color-ember-deep);
  color: var(--bf-color-soot);
}

.bf-demo {
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-panel);
  margin: 0 0 var(--bf-space-4);
  padding: var(--bf-space-3);
}

.bf-demo__grid {
  display: grid;
  grid-template-columns: repeat(var(--bf-demo-cols), 1fr);
  gap: var(--bf-space-board-gap);
  max-width: var(--bf-space-board-max);
  margin: 0 auto;
}

.bf-demo__cell {
  aspect-ratio: 1;
  display: grid;
  place-items: center;
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-control);
  background: var(--bf-color-char2);
  color: var(--bf-color-ash);
  font-variant-numeric: tabular-nums;
  transition: background var(--bf-motion-cell-transition) ease;
}

.bf-demo__cell[data-state='break'] {
  background: var(--bf-color-soot);
  border-color: var(--bf-color-break-border);
}

.bf-demo__cell[data-state='spark'] { color: var(--bf-color-flame); }

.bf-demo__cell[data-state='clue'] {
  background: var(--bf-color-char);
  color: var(--bf-color-paper);
  font-weight: 600;
}

.bf-demo__cell[data-state='burn'] { color: var(--bf-color-soot); }

.bf-demo__cell[data-hl='route'] { outline: 2px solid var(--bf-color-flame); outline-offset: -1px; }
.bf-demo__cell[data-hl='wall'] { outline: 2px solid var(--bf-color-break-border); outline-offset: -1px; }
.bf-demo__cell[data-hl='focus'] { outline: 2px solid var(--bf-color-ember); outline-offset: -1px; }

.bf-demo__hud {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  color: var(--bf-color-ash);
  font-size: var(--bf-type-label);
  letter-spacing: var(--bf-tracking-label);
  text-transform: uppercase;
  margin: var(--bf-space-3) 0 var(--bf-space-2);
}

.bf-demo__cap {
  color: var(--bf-color-paper);
  font-size: var(--bf-type-body);
  margin: 0 0 var(--bf-space-3);
  min-height: 3em;
}

.bf-demo__controls { display: flex; gap: var(--bf-space-2); }

.bf-practice__intro {
  color: var(--bf-color-ash);
  font-size: var(--bf-type-hint);
  margin: 0 0 var(--bf-space-3);
}

.bf-lesson__foot { margin-top: var(--bf-space-4); }

.bf-lesson__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--bf-space-2);
  margin-top: var(--bf-space-3);
}

@media (prefers-reduced-motion: reduce) {
  .bf-demo__cell { transition: none; }
}

.bf-app[data-motion='reduced'] .bf-demo__cell { transition: none; }
`;
</content>
