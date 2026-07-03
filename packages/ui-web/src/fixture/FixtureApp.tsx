/**
 * The fixture page app: one playable puzzle (the README demo board) wired
 * through PlaySession, with HUD and the burn replay on containment. Mounted
 * by fixture/main.tsx (vite dev harness) and rendered directly in tests;
 * WS-17's e2e + axe scan reuse this page. NOT app chrome — that is WS-09.
 */
import { useEffect, useReducer, useState } from 'react';
import type { ReactElement } from 'react';
import { PlaySession, revealSequence } from '@burnfront/game-core';
import type { RevealSequence } from '@burnfront/game-core';
import { Board } from '../Board';
import { BurnReplay } from '../BurnReplay';
import { BreaksCounter, CluePill } from '../hud';
import { BurnfrontStyles } from '../styles';
import { fixtureBoard } from './fixtureBoard';
import {
  boardLabel,
  boardStrings,
  hudStrings,
  replayLabels,
  replayStrings,
} from './fixtureStrings';

export interface FixtureAppProps {
  /** Forwarded to BurnReplay (tests pin it; the page follows the media query). */
  readonly reducedMotion?: boolean;
}

function formatTime(ms: number): string {
  const totalSeconds = Math.max(0, Math.floor(ms / 1000));
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${String(minutes)}:${String(seconds).padStart(2, '0')}`;
}

interface Contained {
  readonly sequence: RevealSequence;
  readonly timeText: string;
}

export function FixtureApp({ reducedMotion }: FixtureAppProps): ReactElement {
  const [session] = useState(
    () => new PlaySession({ board: fixtureBoard, mode: 'endless' }, { now: () => Date.now() }),
  );
  const [, bump] = useReducer((n: number) => n + 1, 0);
  const [contained, setContained] = useState<Contained | null>(null);

  useEffect(() => {
    session.start();
  }, [session]);

  return (
    <main>
      <BurnfrontStyles />
      <h1>Burnfront — component fixture</h1>
      <div className="bf-fixture__hud">
        <BreaksCounter
          placed={session.breaksPlaced}
          total={session.board.breaks}
          strings={hudStrings}
        />
        {session.board.clues.map((clue) => (
          <CluePill key={`${String(clue.r)}-${String(clue.c)}`} minute={clue.m} />
        ))}
      </div>
      {contained === null ? (
        <Board
          session={session}
          strings={boardStrings}
          label={boardLabel}
          onChange={() => {
            bump();
          }}
          onComplete={(result) => {
            if (!result.valid) return;
            session.pause();
            setContained({
              sequence: revealSequence(session.board, session.shading()),
              timeText: formatTime(session.elapsedMs()),
            });
          }}
        />
      ) : (
        <BurnReplay
          board={session.board}
          shading={session.shading()}
          sequence={contained.sequence}
          strings={replayStrings}
          labels={replayLabels}
          timeText={contained.timeText}
          {...(reducedMotion !== undefined ? { reducedMotion } : {})}
        />
      )}
    </main>
  );
}
