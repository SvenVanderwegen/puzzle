/**
 * <HeroApp> — the landing hero's live board (WS-15, product.md §2.1).
 *
 * Hydrates in place of the server-rendered static hero: the same fixture
 * board, now wired through a game-core PlaySession into the ui-web <Board>.
 * Solving it swaps to the burn replay and shows the "new one at midnight"
 * card; an invalid full shading shows the play.wrong line (board only
 * judges itself when all breaks are down — game-core behavior).
 */
import { useEffect, useReducer, useState } from 'react';
import type { ReactElement } from 'react';
import type { BoardSpec } from '@burnfront/engine';
import { PlaySession, revealSequence } from '@burnfront/game-core';
import type { RevealSequence } from '@burnfront/game-core';
import { Board, BreaksCounter, BurnReplay } from '@burnfront/ui-web';
import { t } from '../strings';
import { landingCopy } from './copy';

export interface HeroAppProps {
  readonly board: BoardSpec;
  /** Forwarded to BurnReplay (tests pin it; the page follows the media query). */
  readonly reducedMotion?: boolean;
}

interface Contained {
  readonly sequence: RevealSequence;
  readonly timeText: string;
}

function formatTime(ms: number): string {
  const totalSeconds = Math.max(0, Math.floor(ms / 1000));
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${String(minutes)}:${String(seconds).padStart(2, '0')}`;
}

const boardStrings = {
  'a11y.cell.empty': t('a11y.cell.empty'),
  'a11y.cell.break': t('a11y.cell.break'),
  'a11y.cell.dot': t('a11y.cell.dot'),
  'a11y.cell.clue': t('a11y.cell.clue'),
  'a11y.cell.spark': t('a11y.cell.spark'),
} as const;

const replayStrings = {
  'a11y.replay.minute': t('a11y.replay.minute'),
  'a11y.contained': t('a11y.contained'),
  'play.contained': t('play.contained'),
} as const;

const replayLabels = {
  watchAgain: t('replay.watchAgain'),
  nextMinute: t('replay.nextMinute'),
  previousMinute: t('replay.previousMinute'),
} as const;

export function HeroApp({ board, reducedMotion }: HeroAppProps): ReactElement {
  const [session] = useState(
    () => new PlaySession({ board, mode: 'endless' }, { now: () => Date.now() }),
  );
  const [, bump] = useReducer((n: number) => n + 1, 0);
  const [contained, setContained] = useState<Contained | null>(null);
  const [wrong, setWrong] = useState(false);

  useEffect(() => {
    session.start();
  }, [session]);

  if (contained !== null) {
    return (
      <div className="bf-hero-live">
        <BurnReplay
          board={board}
          shading={session.shading()}
          sequence={contained.sequence}
          strings={replayStrings}
          labels={replayLabels}
          timeText={contained.timeText}
          {...(reducedMotion !== undefined ? { reducedMotion } : {})}
        />
        <p className="bf-hero-card" data-testid="hero-solved-card">
          <a href="/daily">{landingCopy['landing.hero.solved']}</a>
        </p>
      </div>
    );
  }

  return (
    <div className="bf-hero-live">
      <Board
        session={session}
        strings={boardStrings}
        label={t('a11y.board')}
        onChange={() => {
          // Keep the wrong-report line while the bad shading is still fully
          // placed (stroke-close fires onChange right after onComplete);
          // clear it as soon as the player lifts a break.
          if (session.completion() === null) setWrong(false);
          bump();
        }}
        onComplete={(result) => {
          if (!result.valid) {
            setWrong(true);
            return;
          }
          session.pause();
          setContained({
            sequence: revealSequence(board, session.shading()),
            timeText: formatTime(session.elapsedMs()),
          });
        }}
      />
      <p className="bf-hero-hud">
        <BreaksCounter
          placed={session.breaksPlaced}
          total={board.breaks}
          strings={{ 'play.breaks': t('play.breaks') }}
        />
        {wrong && (
          <span className="bf-hero-wrong" role="status" data-testid="hero-wrong">
            {t('play.wrong', { n: board.breaks })}
          </span>
        )}
      </p>
    </div>
  );
}
