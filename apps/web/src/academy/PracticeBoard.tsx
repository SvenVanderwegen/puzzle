/**
 * <PracticeBoard> — one Academy practice board, played through the normal
 * game-core PlaySession + ui-web Board (same surface as Endless/Daily), then
 * the BurnReplay on containment. Unrated (RATING.md §3): no tier, no rating
 * chip, no Coach. Mode is `pack` with the pack puzzle_id so a signed-in
 * contain can sync as a mode=pack solve (packSync.ts) — the parent decides.
 *
 * Remounted per board by the parent (key={puzzleId}), so the session and stage
 * reset cleanly between the two practice boards.
 */
import { useCallback, useMemo, useRef, useState, type ReactElement } from 'react';
import type { BoardSpec, BurnResult } from '@burnfront/engine';
import { PlaySession, revealSequence, type RevealSequence } from '@burnfront/game-core';
import { Board, BreaksCounter, BurnReplay } from '@burnfront/ui-web';
import { formatElapsed } from '../state/clock';
import { useRuntime } from '../state/runtime';
import { t } from '../strings';
import type { PracticePuzzleId } from './boards';

const BOARD_STRINGS = {
  'a11y.cell.empty': t('a11y.cell.empty'),
  'a11y.cell.break': t('a11y.cell.break'),
  'a11y.cell.dot': t('a11y.cell.dot'),
  'a11y.cell.clue': t('a11y.cell.clue'),
  'a11y.cell.spark': t('a11y.cell.spark'),
} as const;

const REPLAY_STRINGS = {
  'a11y.replay.minute': t('a11y.replay.minute'),
  'a11y.contained': t('a11y.contained'),
  'play.contained': t('play.contained'),
} as const;

const REPLAY_LABELS = {
  watchAgain: t('replay.watchAgain'),
  nextMinute: t('replay.nextMinute'),
  previousMinute: t('replay.previousMinute'),
} as const;

const HUD_STRINGS = { 'play.breaks': t('play.breaks') } as const;

interface Finale {
  readonly sequence: RevealSequence;
  readonly elapsedMs: number;
  readonly shading: readonly boolean[];
}

export interface PracticeBoardProps {
  readonly puzzleId: PracticePuzzleId;
  readonly board: BoardSpec;
  /** 1-based board number within the lesson. */
  readonly index: number;
  readonly total: number;
  readonly reducedMotion?: boolean;
  /** The final board is contained — parent syncs + advances. */
  readonly onContained: (session: PlaySession) => void;
  readonly onNext: () => void;
  /** Copy for the advance button (last board differs from the others). */
  readonly nextLabel: string;
}

export function PracticeBoard({
  puzzleId,
  board,
  index,
  total,
  reducedMotion,
  onContained,
  onNext,
  nextLabel,
}: PracticeBoardProps): ReactElement {
  const { clock } = useRuntime();
  const session = useMemo(() => {
    const created = new PlaySession({ board, mode: 'pack', puzzleId }, clock);
    created.start();
    return created;
    // A fresh session per board; the parent remounts on puzzleId change.
  }, [board, puzzleId, clock]);

  const [, bump] = useState(0);
  const [wrong, setWrong] = useState(false);
  const [finale, setFinale] = useState<Finale | null>(null);
  const contained = useRef(false);

  const handleChange = useCallback(() => {
    bump((n) => n + 1);
    if (session.completion() === null) setWrong(false);
  }, [session]);

  const handleComplete = useCallback(
    (result: BurnResult) => {
      if (contained.current) return;
      if (!result.valid) {
        setWrong(true);
        return;
      }
      contained.current = true;
      session.pause();
      setFinale({
        sequence: revealSequence(session.board, session.shading()),
        elapsedMs: session.elapsedMs(),
        shading: session.shading(),
      });
      setWrong(false);
      onContained(session);
    },
    [session, onContained],
  );

  return (
    <section aria-label={t('academy.practice.heading', { n: index, total })}>
      <p className="bf-practice__intro">{t('academy.practice.heading', { n: index, total })}</p>
      {finale === null ? (
        <>
          <p className="bf-practice__intro">{t('academy.practice.intro')}</p>
          <div className="bf-lane__row">
            <BreaksCounter
              placed={session.breaksPlaced}
              total={session.board.breaks}
              strings={HUD_STRINGS}
            />
          </div>
          <Board
            session={session}
            strings={BOARD_STRINGS}
            label={t('a11y.board')}
            onChange={handleChange}
            onComplete={handleComplete}
          />
          {wrong ? (
            <p className="bf-hint" role="status">
              {t('play.wrong', { n: session.board.breaks })}
            </p>
          ) : null}
        </>
      ) : (
        <>
          <BurnReplay
            board={session.board}
            shading={finale.shading}
            sequence={finale.sequence}
            strings={REPLAY_STRINGS}
            labels={REPLAY_LABELS}
            timeText={formatElapsed(finale.elapsedMs)}
            {...(reducedMotion === undefined ? {} : { reducedMotion })}
          />
          <p>{t('play.stats.time', { time: formatElapsed(finale.elapsedMs) })}</p>
          <button type="button" className="bf-play" onClick={onNext} data-testid="practice-next">
            {nextLabel}
          </button>
        </>
      )}
    </section>
  );
}
</content>
