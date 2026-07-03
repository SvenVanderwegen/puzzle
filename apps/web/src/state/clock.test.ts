import { describe, expect, it } from 'vitest';
import {
  countdownParts,
  formatElapsed,
  msUntilUtcMidnight,
  utcDateOf,
  utcDayBefore,
} from './clock';

const T = (iso: string): number => Date.parse(iso);

describe('UTC day math (ADR-0002)', () => {
  it('utcDateOf uses UTC boundaries, not local ones', () => {
    expect(utcDateOf(T('2026-07-03T00:00:00Z'))).toBe('2026-07-03');
    expect(utcDateOf(T('2026-07-03T23:59:59Z'))).toBe('2026-07-03');
    expect(utcDateOf(T('2026-07-04T00:00:00Z'))).toBe('2026-07-04');
  });

  it('utcDayBefore crosses months and years', () => {
    expect(utcDayBefore('2026-07-01')).toBe('2026-06-30');
    expect(utcDayBefore('2026-01-01')).toBe('2025-12-31');
    expect(utcDayBefore('2026-07-03')).toBe('2026-07-02');
  });
});

describe('countdown to the next incident', () => {
  it('counts down to UTC midnight', () => {
    expect(countdownParts(T('2026-07-03T21:18:51Z'))).toEqual({ hh: '02', mm: '41', ss: '09' });
    expect(countdownParts(T('2026-07-03T23:59:59Z'))).toEqual({ hh: '00', mm: '00', ss: '01' });
  });

  it('shows a full day exactly at midnight (the next incident is tomorrow)', () => {
    expect(countdownParts(T('2026-07-03T00:00:00Z'))).toEqual({ hh: '24', mm: '00', ss: '00' });
    expect(msUntilUtcMidnight(T('2026-07-03T00:00:00Z'))).toBe(86_400_000);
  });
});

describe('formatElapsed', () => {
  it('formats m:ss ("Resume — 2:41 elapsed")', () => {
    expect(formatElapsed(161_000)).toBe('2:41');
    expect(formatElapsed(0)).toBe('0:00');
    expect(formatElapsed(59_999)).toBe('0:59');
  });

  it('formats h:mm:ss past an hour and clamps negatives', () => {
    expect(formatElapsed(3_600_000 + 62_000)).toBe('1:01:02');
    expect(formatElapsed(-5)).toBe('0:00');
  });
});
