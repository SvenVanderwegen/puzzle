/**
 * The daily streak flame (product §6). Renders the day count and, when the run
 * was held through a missed day by a freeze/coverage, the "controlled burn"
 * ring (COPY streak.frozen). `frozen` is a display input the win flow derives
 * (server streak survived a gap, or an amnestied/covered day) — the contract's
 * Streak object exposes no per-solve freeze bit, so the surface computes it.
 * Renders nothing at streak 0.
 */
import type { ReactElement } from 'react';
import { t } from '../strings';

export interface StreakFlameProps {
  readonly streak: number;
  readonly frozen: boolean;
}

export function StreakFlame({ streak, frozen }: StreakFlameProps): ReactElement | null {
  if (streak <= 0) return null;
  return (
    <p
      className="bf-flame"
      data-testid="streak-flame"
      data-streak={streak}
      data-frozen={frozen}
      role="status"
    >
      <span className="bf-flame__count">{t('streak.days', { n: streak })}</span>
      {frozen ? (
        <span className="bf-flame__ring" data-testid="streak-freeze-ring">
          {t('streak.frozen')}
        </span>
      ) : null}
    </p>
  );
}
