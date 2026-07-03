/**
 * Client-generated, spoiler-free daily share (product §6 + COPY.md ## share).
 *
 * The "burn signature" is one emoji per burn minute, colored by how many cells
 * ignited THAT minute: 🟥 ≥4 · 🟧 2–3 · 🟨 1. It is a histogram of the fire's
 * spread — identical for every correct solver of the board — so it carries
 * ZERO positional or solution information: no cell coordinates, no break
 * positions, nothing a receiver could use to shortcut the puzzle. The share
 * text is only ever built for a contained board (no card on fail).
 */
import type { RevealSequence } from '@burnfront/game-core';
import { t } from '../strings';

/** One signature glyph for a minute in which `count` cells ignited. */
function minuteEmoji(count: number): string {
  if (count >= 4) return '🟥';
  if (count >= 2) return '🟧';
  return '🟨';
}

/**
 * The burn-signature string: one emoji per frame (minute) of the reveal
 * sequence. revealSequence only emits frames with ≥1 igniting cell, so every
 * glyph maps to a real minute of spread and the string leaks no positions.
 */
export function burnSignature(sequence: RevealSequence): string {
  return sequence.frames.map((frame) => minuteEmoji(frame.cells.length)).join('');
}

export interface ShareCard {
  /** Incident number (daily.incident_number). */
  readonly incident: number;
  /** UTC incident date (share.url {date}). */
  readonly date: string;
  /** Formatted solve time ("3:12"). */
  readonly timeText: string;
  /** True only when the contain used zero hints (COPY: ✅ shows only if clean). */
  readonly clean: boolean;
  /** The player's streak; 🔥 shows only at ≥2 (share.line2 plural). */
  readonly streak: number;
  readonly sequence: RevealSequence;
}

/** share.line2 — "⏱ {time}{ · ✅ clean}{ · 🔥 #}" via the ICU select/plural. */
export function shareLine2(timeText: string, clean: boolean, streak: number): string {
  return t('share.line2', {
    time: timeText,
    clean: clean ? 'yes' : 'other',
    streak,
  });
}

/** share.url — the public dated share link (no query, self-canonical). */
export function shareUrl(date: string): string {
  return t('share.url', { date });
}

/**
 * The full share text: headline · burn signature · stats line · link. Four
 * lines, spoiler-free. Assembled here so both navigator.share and the
 * clipboard fallback send the identical payload.
 */
export function shareText(card: ShareCard): string {
  return [
    t('share.headline', { n: card.incident }),
    burnSignature(card.sequence),
    shareLine2(card.timeText, card.clean, card.streak),
    shareUrl(card.date),
  ].join('\n');
}

export type ShareResult = 'shared' | 'copied' | 'failed';

export interface ShareEnv {
  /** navigator.share, when present (mobile/native share sheet). */
  readonly share?: (data: { title?: string; text: string; url?: string }) => Promise<void>;
  /** navigator.clipboard.writeText, the desktop fallback. */
  readonly writeText?: (text: string) => Promise<void>;
}

/**
 * Native share first, clipboard fallback. A user-cancelled share sheet
 * (AbortError) is treated as handled — we do NOT silently copy behind a
 * deliberate cancel. Returns what happened so the caller can show share.copied.
 */
export async function shareOrCopy(
  card: ShareCard,
  env: ShareEnv,
): Promise<ShareResult> {
  const text = shareText(card);
  if (typeof env.share === 'function') {
    try {
      await env.share({ text, url: shareUrl(card.date) });
      return 'shared';
    } catch (error) {
      if (error instanceof Error && error.name === 'AbortError') return 'shared';
      // Fall through to the clipboard on a genuine share failure.
    }
  }
  if (typeof env.writeText === 'function') {
    try {
      await env.writeText(text);
      return 'copied';
    } catch {
      return 'failed';
    }
  }
  return 'failed';
}

/** Reads navigator's share/clipboard capabilities (bound); DOM-only, no state. */
export function browserShareEnv(): ShareEnv {
  if (typeof navigator === 'undefined') return {};
  const env: { share?: ShareEnv['share']; writeText?: ShareEnv['writeText'] } = {};
  if (typeof navigator.share === 'function') env.share = navigator.share.bind(navigator);
  const clipboard = navigator.clipboard;
  if (clipboard !== undefined && typeof clipboard.writeText === 'function') {
    env.writeText = clipboard.writeText.bind(clipboard);
  }
  return env;
}
