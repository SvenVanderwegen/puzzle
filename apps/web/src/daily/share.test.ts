/**
 * Share generation — the CRITICAL spoiler-freeness guarantee (WS-10 acceptance
 * #1): rendered shares for a known-solution board must carry zero positional or
 * solution information. Plus the burn-signature histogram mapping, share.line2
 * ICU branches, and the navigator.share / clipboard fallback ladder.
 */
import { describe, expect, it, vi } from 'vitest';
import { shadingToBits, type BoardSpec } from '@burnfront/engine';
import { revealSequence } from '@burnfront/game-core';
import { cellName } from '@burnfront/ui-web';
import {
  burnSignature,
  shareLine2,
  shareOrCopy,
  shareText,
  shareUrl,
  type ShareCard,
} from './share';

/** The reference demo board; its unique solution shades cells 8, 11, 17, 22. */
const board: BoardSpec = {
  rows: 5,
  cols: 5,
  spark: { r: 3, c: 0 },
  breaks: 4,
  clues: [
    { r: 1, c: 4, m: 8 },
    { r: 2, c: 2, m: 5 },
    { r: 3, c: 1, m: 1 },
    { r: 4, c: 1, m: 2 },
    { r: 4, c: 3, m: 8 },
  ],
};
const SOLUTION = [8, 11, 17, 22];

function solutionShading(): boolean[] {
  return Array.from({ length: 25 }, (_, i) => SOLUTION.includes(i));
}

function card(overrides: Partial<ShareCard> = {}): ShareCard {
  return {
    incident: 142,
    date: '2026-07-08',
    timeText: '2:41',
    clean: true,
    streak: 5,
    sequence: revealSequence(board, solutionShading()),
    ...overrides,
  };
}

describe('burnSignature', () => {
  it('emits one glyph per burn minute, colored purely by cells ignited', () => {
    const sequence = revealSequence(board, solutionShading());
    const signature = burnSignature(sequence);
    // One emoji per frame (minute); every glyph is one of the three buckets.
    expect((signature.match(/[🟥🟧🟨]/gu) ?? []).length).toBe(sequence.frames.length);
    expect(/^[🟥🟧🟨]+$/u.test(signature)).toBe(true);
    // The mapping is a pure function of the per-minute count — recompute it
    // independently and require an exact match (counts-only, no positions).
    const expected = sequence.frames
      .map((f) => (f.cells.length >= 4 ? '🟥' : f.cells.length >= 2 ? '🟧' : '🟨'))
      .join('');
    expect(signature).toBe(expected);
  });
});

describe('spoiler-freeness (acceptance #1)', () => {
  it('leaks no coordinates, no solution bits, no break positions', () => {
    const text = shareText(card());
    // The solution bit string never appears.
    expect(text).not.toContain(shadingToBits(solutionShading()));
    // No cell name (A1-style) appears — so no break position can be read off.
    for (let i = 0; i < 25; i += 1) {
      expect(text).not.toContain(cellName(i, board.cols));
    }
    // With the histogram signature removed, the remaining board-derived content
    // is empty: what's left is exactly the fixed template (headline/stats/url).
    const withoutSignature = text.replace(burnSignature(card().sequence), '§');
    expect(withoutSignature).toBe(
      [
        'Burnfront — Incident #142 CONTAINED',
        '§',
        shareLine2('2:41', true, 5),
        shareUrl('2026-07-08'),
      ].join('\n'),
    );
  });

  it('the signature is identical for any correct solver of the same board', () => {
    // Two independent solves of the same board produce the same reveal (the
    // burn is board-determined) — so the signature carries nothing solver- or
    // position-specific.
    const a = burnSignature(revealSequence(board, solutionShading()));
    const b = burnSignature(revealSequence(board, solutionShading()));
    expect(a).toBe(b);
  });
});

describe('shareLine2 ICU branches', () => {
  it('adds ✅ only when clean', () => {
    expect(shareLine2('2:41', true, 0)).toContain('✅');
    expect(shareLine2('2:41', false, 0)).not.toContain('✅');
  });

  it('adds 🔥 only at streak ≥ 2', () => {
    expect(shareLine2('2:41', true, 1)).not.toContain('🔥');
    expect(shareLine2('2:41', true, 0)).not.toContain('🔥');
    const two = shareLine2('2:41', false, 2);
    expect(two).toContain('🔥');
    expect(two).toContain('2');
  });

  it('always leads with the timer', () => {
    expect(shareLine2('9:59', false, 0)).toContain('9:59');
  });
});

describe('shareUrl', () => {
  it('renders the dated public link', () => {
    expect(shareUrl('2026-07-08')).toBe('burnfront.com/daily/2026-07-08');
  });
});

describe('shareOrCopy', () => {
  it('uses navigator.share when present', async () => {
    const share = vi.fn().mockResolvedValue(undefined);
    const writeText = vi.fn().mockResolvedValue(undefined);
    const result = await shareOrCopy(card(), { share, writeText });
    expect(result).toBe('shared');
    expect(share).toHaveBeenCalledOnce();
    expect(writeText).not.toHaveBeenCalled();
  });

  it('falls back to the clipboard when share is unavailable', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    const result = await shareOrCopy(card(), { writeText });
    expect(result).toBe('copied');
    expect(writeText).toHaveBeenCalledWith(shareText(card()));
  });

  it('treats a cancelled share sheet as handled (no silent copy)', async () => {
    const abort = Object.assign(new Error('cancelled'), { name: 'AbortError' });
    const share = vi.fn().mockRejectedValue(abort);
    const writeText = vi.fn().mockResolvedValue(undefined);
    const result = await shareOrCopy(card(), { share, writeText });
    expect(result).toBe('shared');
    expect(writeText).not.toHaveBeenCalled();
  });

  it('falls through to the clipboard on a genuine share failure', async () => {
    const share = vi.fn().mockRejectedValue(new Error('boom'));
    const writeText = vi.fn().mockResolvedValue(undefined);
    const result = await shareOrCopy(card(), { share, writeText });
    expect(result).toBe('copied');
  });

  it('reports failure when nothing can send', async () => {
    expect(await shareOrCopy(card(), {})).toBe('failed');
  });
});
