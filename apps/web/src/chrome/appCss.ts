/**
 * Shell stylesheet — the night incident map. Every color is a `var(--bf-*)`
 * custom property emitted by ui-web's tokens.ts from
 * contracts/design-tokens.json; the type scale below is emitted from the same
 * contract here. No raw hex anywhere in apps/web (tripwire: tripwires.test.ts).
 * System font stack only (ADR-0009); tabular numerals for all counters.
 * Secondary text uses the `ash` token (ashDim fails WCAG 4.5:1 on soot/char;
 * the mute comes from the label/hint type scale instead).
 */
import { designTokens, tokensCssText } from '@burnfront/ui-web';

function typeScaleCss(): string {
  const scale = designTokens.type.scale;
  return `:root {
  --bf-type-h1: ${scale.h1};
  --bf-type-lede: ${scale.lede};
  --bf-type-body: ${scale.body};
  --bf-type-label: ${scale.label};
  --bf-type-hint: ${scale.hint};
  --bf-tracking-label: ${designTokens.type.labelTracking};
  --bf-tracking-eyebrow: ${designTokens.type.eyebrowTracking};
}`;
}

export const appCss = `
* { box-sizing: border-box; }

html, body {
  margin: 0;
  min-height: 100%;
}

body {
  background: var(--bf-color-soot);
  color: var(--bf-color-ash);
  font-family: var(--bf-font-body);
  font-size: var(--bf-type-body);
  line-height: 1.5;
  font-variant-numeric: tabular-nums;
}

.bf-vh {
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

.bf-app {
  max-width: 720px;
  margin: 0 auto;
  padding: var(--bf-space-4) var(--bf-space-4) var(--bf-space-7);
}

.bf-header {
  display: flex;
  align-items: baseline;
  gap: var(--bf-space-3);
  border-bottom: 1px solid var(--bf-color-line);
  padding-bottom: var(--bf-space-3);
}

.bf-header__eyebrow {
  display: block;
  color: var(--bf-color-ash);
  font-size: var(--bf-type-label);
  letter-spacing: var(--bf-tracking-eyebrow);
  text-transform: uppercase;
}

.bf-header__title {
  color: var(--bf-color-paper);
  font-family: var(--bf-font-display);
  font-size: var(--bf-type-lede);
  letter-spacing: var(--bf-tracking-label);
  text-decoration: none;
  text-transform: uppercase;
}

.bf-header__spacer { flex: 1; }

.bf-chip {
  display: inline-block;
  background: var(--bf-color-char2);
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-control);
  color: var(--bf-color-ash);
  font-size: var(--bf-type-label);
  letter-spacing: var(--bf-tracking-label);
  padding: 2px var(--bf-space-2);
  text-decoration: none;
  text-transform: uppercase;
}

.bf-main { padding-top: var(--bf-space-5); }

.bf-page-heading {
  color: var(--bf-color-paper);
  font-family: var(--bf-font-display);
  font-size: var(--bf-type-lede);
  letter-spacing: var(--bf-tracking-label);
  margin: 0 0 var(--bf-space-3);
  text-transform: uppercase;
  outline: none;
}

.bf-offline {
  background: var(--bf-color-char2);
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-panel);
  color: var(--bf-color-paper);
  margin: var(--bf-space-3) 0 0;
  padding: var(--bf-space-2) var(--bf-space-3);
}

.bf-play {
  display: block;
  background: var(--bf-color-ember);
  border: 1px solid var(--bf-color-ember-deep);
  border-radius: var(--bf-radius-control);
  color: var(--bf-color-soot);
  font-family: var(--bf-font-display);
  font-size: var(--bf-type-lede);
  letter-spacing: var(--bf-tracking-label);
  margin: 0 0 var(--bf-space-4);
  padding: var(--bf-space-3) var(--bf-space-4);
  text-align: center;
  text-decoration: none;
  text-transform: uppercase;
}

.bf-play:focus-visible {
  outline: 2px solid var(--bf-color-flame);
  outline-offset: 2px;
}

.bf-countdown {
  color: var(--bf-color-ash);
  font-size: var(--bf-type-hint);
  margin: 0 0 var(--bf-space-4);
}

.bf-lane {
  background: var(--bf-color-char);
  border: 1px solid var(--bf-color-line);
  border-radius: var(--bf-radius-panel);
  margin-bottom: var(--bf-space-3);
  padding: var(--bf-space-3) var(--bf-space-4);
}

.bf-lane__title {
  color: var(--bf-color-paper);
  font-size: var(--bf-type-lede);
  font-weight: 600;
  margin: 0 0 var(--bf-space-2);
}

.bf-lane__title a {
  color: var(--bf-color-paper);
  text-decoration: none;
}

.bf-lane__meta {
  color: var(--bf-color-ash);
  font-size: var(--bf-type-hint);
  margin: var(--bf-space-1) 0 0;
}

.bf-lane__row {
  display: flex;
  flex-wrap: wrap;
  gap: var(--bf-space-2);
  margin-top: var(--bf-space-2);
}

.bf-flame {
  color: var(--bf-color-flame);
  font-size: var(--bf-type-hint);
}

.bf-tier-chip[data-recommended='true'] {
  border-color: var(--bf-color-ember-deep);
  color: var(--bf-color-ember);
}

.bf-rush {
  color: var(--bf-color-ash);
  font-size: var(--bf-type-label);
  letter-spacing: var(--bf-tracking-label);
  margin: var(--bf-space-4) 0 0;
  text-align: center;
  text-transform: uppercase;
}

.bf-stub-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.bf-stub-list li {
  border-top: 1px solid var(--bf-color-line);
  padding: var(--bf-space-2) 0;
}

.bf-hint {
  color: var(--bf-color-ash);
  font-size: var(--bf-type-hint);
}

a { color: var(--bf-color-ember); }
`;

/** Full shell CSS: token variables + type-scale variables + rules. */
export function appCssText(): string {
  return `${tokensCssText()}\n${typeScaleCss()}\n${appCss}`;
}
