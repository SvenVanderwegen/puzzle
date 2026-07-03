import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Clock } from '../state/clock';
import { useCountdown } from './useCountdown';

describe('useCountdown', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it('renders the injected instant and ticks once per second', () => {
    let nowMs = Date.parse('2026-07-03T23:59:58Z');
    const clock: Clock = { now: () => nowMs };
    const { result, unmount } = renderHook(() => useCountdown(clock));
    expect(result.current).toEqual({ hh: '00', mm: '00', ss: '02' });

    act(() => {
      nowMs += 1000;
      vi.advanceTimersByTime(1000);
    });
    expect(result.current).toEqual({ hh: '00', mm: '00', ss: '01' });

    act(() => {
      nowMs += 2000; // cross midnight — the next incident is a day away
      vi.advanceTimersByTime(1000);
    });
    expect(result.current).toEqual({ hh: '23', mm: '59', ss: '59' });
    unmount();
  });
});
