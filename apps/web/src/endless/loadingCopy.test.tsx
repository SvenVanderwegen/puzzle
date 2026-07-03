/**
 * Loading-copy rotation: key 1 immediately, then 2→3→4 and wrap while
 * active; inactive resets to key 1 (product §3 — the fairness guarantees
 * double as loading entertainment).
 */
import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactElement } from 'react';
import { t } from '../strings';
import { LOADING_KEYS, LOADING_ROTATE_MS, useLoadingCopy } from './loadingCopy';

function Probe(props: { readonly active: boolean }): ReactElement {
  const key = useLoadingCopy(props.active);
  return <span data-testid="copy">{t(key)}</span>;
}

afterEach(() => {
  vi.useRealTimers();
});

describe('useLoadingCopy', () => {
  it('rotates through all four fairness lines and wraps', () => {
    vi.useFakeTimers();
    render(<Probe active />);
    expect(screen.getByTestId('copy')).toHaveTextContent(t('play.loading.endless.1'));
    for (const key of [
      'play.loading.endless.2',
      'play.loading.endless.3',
      'play.loading.endless.4',
      'play.loading.endless.1',
    ] as const) {
      act(() => {
        vi.advanceTimersByTime(LOADING_ROTATE_MS);
      });
      expect(screen.getByTestId('copy')).toHaveTextContent(t(key));
    }
  });

  it('resets to the first line when inactive and stops rotating', () => {
    vi.useFakeTimers();
    const { rerender } = render(<Probe active />);
    act(() => {
      vi.advanceTimersByTime(LOADING_ROTATE_MS * 2);
    });
    expect(screen.getByTestId('copy')).toHaveTextContent(t('play.loading.endless.3'));
    rerender(<Probe active={false} />);
    act(() => {
      vi.advanceTimersByTime(LOADING_ROTATE_MS * 5);
    });
    expect(screen.getByTestId('copy')).toHaveTextContent(t('play.loading.endless.1'));
  });

  it('exposes the four catalog keys in rotation order', () => {
    expect(LOADING_KEYS).toEqual([
      'play.loading.endless.1',
      'play.loading.endless.2',
      'play.loading.endless.3',
      'play.loading.endless.4',
    ]);
  });
});
