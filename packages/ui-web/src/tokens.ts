/**
 * Design tokens — the ONLY bridge between contracts/design-tokens.json and
 * component styles. Builds the `--bf-*` CSS custom-property block at build
 * time; components reference `var(--bf-...)` exclusively. No raw hex lives
 * anywhere in this package's source (tripwire: tokens.test.ts).
 */
import designTokens from '../../../contracts/design-tokens.json';

export { designTokens };

/** camelCase → kebab-case for CSS custom-property names. */
function kebab(name: string): string {
  return name.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
}

/**
 * The full `--bf-*` variable map generated from the token JSON:
 * every color, every motion duration (`...Ms` keys, as `ms` values),
 * radii, board spacing and font stacks.
 */
export function cssVariables(): Readonly<Record<string, string>> {
  const vars: Record<string, string> = {};
  for (const [name, def] of Object.entries(designTokens.color)) {
    vars[`--bf-color-${kebab(name)}`] = def.value;
  }
  for (const [name, value] of Object.entries(designTokens.motion)) {
    if (typeof value !== 'number') continue; // $comment
    if (name.endsWith('Ms')) {
      vars[`--bf-motion-${kebab(name.slice(0, -2))}`] = `${String(value)}ms`;
    }
  }
  for (const [name, value] of Object.entries(designTokens.radius)) {
    vars[`--bf-radius-${kebab(name)}`] = `${String(value)}px`;
  }
  vars['--bf-space-board-gap'] = `${String(designTokens.space.boardGap)}px`;
  vars['--bf-space-board-max'] = `${String(designTokens.space.boardMax)}px`;
  designTokens.space.scale.forEach((step, i) => {
    vars[`--bf-space-${String(i + 1)}`] = `${String(step)}px`;
  });
  vars['--bf-font-display'] = designTokens.type.display.family;
  vars['--bf-font-body'] = designTokens.type.body.family;
  return vars;
}

/** The variable block as a CSS rule, ready for a `<style>` tag. */
export function tokensCssText(selector = ':root'): string {
  const lines = Object.entries(cssVariables())
    .map(([name, value]) => `  ${name}: ${value};`)
    .join('\n');
  return `${selector} {\n${lines}\n}`;
}

function hexChannel(hex: string, channel: number): number {
  const start = 1 + channel * 2;
  return parseInt(hex.slice(start, start + 2), 16);
}

/**
 * Burnt-cell background for minute `t` on a board whose max minute is `T`,
 * per the burnRamp formula frozen in design-tokens.json:
 * c = from + (to - from) * f, f = fMin + fSpan * (t / T).
 * Early minutes read flame-hot, late minutes deep ember.
 */
export function burnColor(minute: number, maxMinute: number): string {
  const ramp = designTokens.burnRamp;
  const f = ramp.fMin + ramp.fSpan * (maxMinute > 0 ? minute / maxMinute : 0);
  const mix = (channel: number): number => {
    const a = hexChannel(ramp.from, channel);
    const b = hexChannel(ramp.to, channel);
    return Math.round(a + (b - a) * f);
  };
  return `rgb(${String(mix(0))}, ${String(mix(1))}, ${String(mix(2))})`;
}

/** Motion durations and counts, typed for the replay/board state machines. */
export const motion = {
  cellSettleMs: designTokens.motion.cellSettleMs,
  replayMinuteMs: designTokens.motion.replayMinuteMs,
  replayMinuteFastMs: designTokens.motion.replayMinuteFastMs,
  replayAccelAfterMinute: designTokens.motion.replayAccelAfterMinute,
  replayFlashMs: designTokens.motion.replayFlashMs,
  containedStampMs: designTokens.motion.containedStampMs,
  containedBeatMs: designTokens.motion.containedBeatMs,
} as const;
