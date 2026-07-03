/**
 * Token plumbing tests: the --bf-* block is generated from
 * contracts/design-tokens.json (every color, no exceptions), component
 * styles reference generated variables only, and NO raw hex exists anywhere
 * in this package's source (the repo lint does not enforce that yet — this
 * is the package-local tripwire).
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { silentPlayer, soundVerbs } from './audio';
import { uiWebCss } from './styles';
import { burnColor, cssVariables, designTokens, motion, tokensCssText } from './tokens';

const kebab = (s: string): string => s.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();

describe('cssVariables', () => {
  it('emits every color token as --bf-color-*', () => {
    const vars = cssVariables();
    for (const [name, def] of Object.entries(designTokens.color)) {
      expect(vars[`--bf-color-${kebab(name)}`]).toBe(def.value);
    }
    expect(Object.keys(designTokens.color)).toHaveLength(15);
  });

  it('emits motion durations as ms values', () => {
    const vars = cssVariables();
    expect(vars['--bf-motion-cell-settle']).toBe('80ms');
    expect(vars['--bf-motion-replay-minute']).toBe('320ms');
    expect(vars['--bf-motion-replay-minute-fast']).toBe('180ms');
    expect(vars['--bf-motion-replay-flash']).toBe('80ms');
    // a count, not a duration — must not become a CSS length
    expect(vars['--bf-motion-replay-accel-after-minute']).toBeUndefined();
  });

  it('emits radii, board spacing and font stacks', () => {
    const vars = cssVariables();
    expect(vars['--bf-radius-cell']).toBe('5px');
    expect(vars['--bf-space-board-gap']).toBe('5px');
    expect(vars['--bf-space-board-max']).toBe('540px');
    expect(vars['--bf-font-display']).toContain('Staatliches');
  });

  it('tokensCssText produces a :root rule containing every variable', () => {
    const css = tokensCssText();
    expect(css.startsWith(':root {')).toBe(true);
    for (const [name, value] of Object.entries(cssVariables())) {
      expect(css).toContain(`${name}: ${value};`);
    }
  });
});

describe('burnColor (burnRamp formula)', () => {
  const ramp = designTokens.burnRamp;
  const channel = (hex: string, i: number): number => parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16);
  const expected = (f: number): string => {
    const mix = (i: number): number =>
      Math.round(channel(ramp.from, i) + (channel(ramp.to, i) - channel(ramp.from, i)) * f);
    return `rgb(${String(mix(0))}, ${String(mix(1))}, ${String(mix(2))})`;
  };

  it('minute 0 reads flame-hot (f = fMin)', () => {
    expect(burnColor(0, 11)).toBe(expected(ramp.fMin));
  });

  it('the last minute reads deep ember (f = fMin + fSpan)', () => {
    expect(burnColor(11, 11)).toBe(expected(ramp.fMin + ramp.fSpan));
  });

  it('interpolates linearly in between', () => {
    expect(burnColor(5, 10)).toBe(expected(ramp.fMin + ramp.fSpan * 0.5));
  });

  it('a zero-minute board never divides by zero', () => {
    expect(burnColor(0, 0)).toBe(expected(ramp.fMin));
  });

  it('motion constants mirror the JSON', () => {
    expect(motion.replayMinuteMs).toBe(designTokens.motion.replayMinuteMs);
    expect(motion.replayAccelAfterMinute).toBe(designTokens.motion.replayAccelAfterMinute);
  });
});

describe('style discipline', () => {
  it('every var(--bf-*) referenced by the stylesheet is generated or runtime-set', () => {
    const generated = new Set(Object.keys(cssVariables()));
    // set from JS at runtime: column count and per-cell burn color
    generated.add('--bf-cols');
    generated.add('--bf-burn-bg');
    const used = [...uiWebCss.matchAll(/var\((--bf-[a-z0-9-]+)/g)].map((m) => m[1]);
    expect(used.length).toBeGreaterThan(0);
    for (const name of used) expect(generated).toContain(name);
  });

  it('no raw hex color anywhere in src/ or fixture/ (tokens only)', () => {
    // vitest cwd is the package dir; import.meta.url is vite-rewritten.
    const roots = [join(process.cwd(), 'src'), join(process.cwd(), 'fixture')];
    const offenders: string[] = [];
    const scan = (dir: string): void => {
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);
        if (entry.isDirectory()) {
          scan(full);
          continue;
        }
        if (!/\.(ts|tsx|html|css)$/.test(entry.name)) continue;
        const text = readFileSync(full, 'utf8');
        if (/#[0-9a-fA-F]{3,8}\b/.test(text)) offenders.push(full);
      }
    };
    for (const root of roots) scan(root);
    expect(offenders).toEqual([]);
  });

  it('the audio stub mirrors the sound verbs frozen in design-tokens.json', () => {
    expect(soundVerbs).toEqual(['shade', 'dot', 'unmark', 'replayTick', 'contained']);
    expect(() => {
      silentPlayer.play('shade');
    }).not.toThrow();
  });
});
