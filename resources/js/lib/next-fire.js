/* Words for a NextFire payload (app/Support/Burnfront/NextFire.php), which
   the server sends as data so the copy stays in the locale files. */

import { t } from './i18n';

/** "Campaign, level 7" / "Quick fire 5 by 5", the small line under "Next fire". */
export function nextFireDetail(next) {
    if (!next) return '';
    if (next.kind === 'campaign') return t('progress.nextCampaign', { level: next.level });
    return t('progress.nextQuick', { rows: next.rows, cols: next.cols });
}

/** Eyebrow, meta line and button text for the home's "Up next" card. */
export function upNextText(next) {
    if (!next) return null;
    if (next.kind === 'campaign') {
        return {
            eyebrow: t('progress.upNextCampaign'),
            meta: t('progress.fireMeta', { level: next.level, rows: next.rows, cols: next.cols }),
            action: t('progress.playFire', { level: next.level }),
        };
    }
    return {
        eyebrow: t('progress.upNextQuick'),
        meta: t('progress.quickMeta', { rows: next.rows, cols: next.cols }),
        action: t('progress.playQuick', { rows: next.rows, cols: next.cols }),
    };
}
