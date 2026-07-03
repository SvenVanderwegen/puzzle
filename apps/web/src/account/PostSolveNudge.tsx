/**
 * The post-solve stats-card nudge line (nudges 1 and 2 of product §1's
 * exactly-three; decision in nudges.ts). WS-10/WS-11 mount this at the
 * FOOTER of the stats card — inline, never modal-blocking. Renders nothing
 * for signed-in users.
 */
import { Link } from '@tanstack/react-router';
import type { ReactElement } from 'react';
import { useLocalState } from '../state/runtime';
import { t } from '../strings';
import { decidePostSolveNudge } from './nudges';

export function PostSolveNudge(): ReactElement | null {
  const state = useLocalState();
  const nudge = decidePostSolveNudge(state);
  if (nudge === null) return null;
  if (nudge === 'streak-protect' || nudge === 'streak-protect-capped') {
    // Past 7 days the capped variant states the real WS-20 merge behavior
    // (the last 7 days carry forward) — the plain line would overpromise.
    return (
      <p className="bf-nudge" data-nudge={nudge}>
        <Link to="/login">
          {t(nudge === 'streak-protect-capped' ? 'streak.protect.capped' : 'streak.protect', {
            n: state.streak.current,
          })}
        </Link>
      </p>
    );
  }
  return (
    <p className="bf-nudge bf-hint" data-nudge="guest-note">
      {t('streak.guestNote')}
    </p>
  );
}
