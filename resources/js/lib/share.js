/* The spoiler-free share text: what kind of fire, the time, the hints, and
   the burn curve (how many cells caught fire each minute) as block
   characters. It shows how the fire grew, never where, so posting it
   doesn't give the daily away.

     Burnfront 142 · 2:41 · no hints
     ▂▅▅▇▇▇▄▂▂▂▂▄▂
     burnfront.com
*/

import { burnCurve, curveBlocks } from './heat';
import { t } from './i18n';

function hintsText(hintsUsed) {
    if (!hintsUsed) return t('share.noHints');
    return hintsUsed === 1 ? t('share.hintOne') : t('share.hintMany', { count: hintsUsed });
}

/**
 * @param {object} o
 * @param {'daily'|'archive'|'campaign'|'endless'} o.mode
 * @param {number|null} o.dailyNumber  daily fire number, for daily/archive
 * @param {number|null} o.level        campaign fire number
 * @param {number} o.rows
 * @param {number} o.cols
 * @param {string|null} o.timeText     e.g. "2:41", null for an untimed fire
 * @param {number} o.hintsUsed
 * @param {boolean} o.revealed         the answer was revealed, not solved
 * @param {ArrayLike<number>} o.times  burn minute per cell, -1 for a firebreak
 */
export function buildShareText(o) {
    let head;
    if ((o.mode === 'daily' || o.mode === 'archive') && o.dailyNumber) {
        head = t('share.headDaily', { number: o.dailyNumber });
    } else if (o.mode === 'campaign' && o.level) {
        head = t('share.headCampaign', { level: o.level });
    } else {
        head = t('share.headQuick', { rows: o.rows, cols: o.cols });
    }

    const parts = [head];
    if (o.revealed) {
        parts.push(t('share.revealed'));
    } else {
        if (o.timeText) parts.push(o.timeText);
        parts.push(hintsText(o.hintsUsed));
    }

    const lines = [parts.join(' · ')];
    if (o.times && o.times.length) lines.push(curveBlocks(burnCurve(Array.from(o.times))));
    lines.push(t('share.site'));
    return lines.join('\n');
}

/**
 * Opens the system share sheet where there is one (phones), else copies to
 * the clipboard. Resolves to 'shared', 'copied', 'cancelled' or 'failed'.
 */
export async function shareResult(text) {
    if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
        try {
            await navigator.share({ text });
            return 'shared';
        } catch (e) {
            if (e && e.name === 'AbortError') return 'cancelled';
            /* fall through to the clipboard */
        }
    }
    try {
        await navigator.clipboard.writeText(text);
        return 'copied';
    } catch (e) {
        return 'failed';
    }
}
