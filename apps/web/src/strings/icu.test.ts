import { describe, expect, it } from 'vitest';
import { formatIcu } from './icu';
import { t } from './index';

describe('formatIcu — simple {braces}', () => {
  it('substitutes named params', () => {
    expect(formatIcu('Breaks {placed}/{n}', { placed: 3, n: 8 })).toBe('Breaks 3/8');
  });

  it('leaves unknown placeholders verbatim (ui-web policy)', () => {
    expect(formatIcu('Incident #{n}', {})).toBe('Incident #{n}');
  });

  it('leaves unbalanced braces verbatim', () => {
    expect(formatIcu('broken {n', { n: 1 })).toBe('broken {n');
  });
});

describe('formatIcu — plural (documented case: daily.solvedBy)', () => {
  it('picks `one` and substitutes # for count 1', () => {
    expect(t('daily.solvedBy', { count: 1, n: 142 })).toBe('1 crew has contained Incident #142.');
  });

  it('picks `other` and substitutes # for larger counts', () => {
    expect(t('daily.solvedBy', { count: 12408, n: 142 })).toBe(
      '12408 crews have contained Incident #142.',
    );
  });

  it('prefers exact =N matches over keywords', () => {
    const template = '{n, plural, =0 {none} one {a single one} other {# of them}}';
    expect(formatIcu(template, { n: 0 })).toBe('none');
    expect(formatIcu(template, { n: 1 })).toBe('a single one');
    expect(formatIcu(template, { n: 5 })).toBe('5 of them');
  });

  it('stays verbatim when the plural value is missing', () => {
    expect(formatIcu('{n, plural, other {#}}', {})).toBe('{n, plural, other {#}}');
  });
});

describe('formatIcu — select + plural (documented case: share.line2)', () => {
  it('renders the clean + streak variant', () => {
    expect(t('share.line2', { time: '4:51', clean: 'yes', streak: 13 })).toBe(
      '⏱ 4:51 · ✅ clean · 🔥 13',
    );
  });

  it('renders empty select/plural branches', () => {
    expect(t('share.line2', { time: '4:51', clean: 'no', streak: 0 })).toBe('⏱ 4:51');
    expect(t('share.line2', { time: '4:51', clean: 'no', streak: 1 })).toBe('⏱ 4:51');
  });

  it('falls back to the select `other` branch for unknown values', () => {
    expect(formatIcu('{v, select, yes {Y} other {O}}', { v: 'maybe' })).toBe('O');
  });
});

describe('t — the keyed catalog', () => {
  it('serves plain keys', () => {
    expect(t('app.title')).toBe('Burnfront');
    expect(t('play.contained')).toBe('CONTAINED');
  });

  it('interpolates catalog templates', () => {
    expect(t('hub.countdown', { hh: '02', mm: '41', ss: '09' })).toBe(
      'Next incident at midnight UTC — 02:41:09.',
    );
    expect(t('tier.size', { tier: t('tier.crew'), rows: 6, cols: 6 })).toBe('Crew 6×6');
  });

  it('serves the ADR-0014 replay keys ui-web quarantined', () => {
    expect(t('replay.watchAgain')).toBe('Watch the burn again');
    expect(t('a11y.board')).toBe('Terrain');
  });
});
