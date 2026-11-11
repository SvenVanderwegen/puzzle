import { Share } from 'react-native';

import i18n from '@/i18n';
import { burnCurve, curveBlocks } from '@/engine/game';
import type { Fire } from '@/engine/puzzles';
import { fmtClock } from '@/engine/rules';

/** Spoiler-free result: time, hints and the burn curve, never the map. */
export function shareText(fire: Fire, ms: number, hints: number): string {
  const t = i18n.t.bind(i18n);
  const size = fire.puzzle.rows;
  const label =
    fire.kind === 'daily'
      ? t('result.shareDaily', { number: fire.number })
      : fire.kind === 'campaign'
        ? t('result.shareCampaign', { number: fire.number })
        : t('result.shareQuick', { size });
  return t('result.shareText', {
    label,
    time: fmtClock(ms),
    hints: t('common.hints', { count: hints }),
    curve: curveBlocks(burnCurve(fire.puzzle)),
  });
}

/** Opens the system share sheet. */
export function shareResult(fire: Fire, ms: number, hints: number) {
  return Share.share({ message: shareText(fire, ms, hints) });
}
