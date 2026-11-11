/* Words for where a player stands on the points ladder. A step on that
   ladder is shown as a rank and a grade, "Firefighter 2"; the step after
   it is "Firefighter 3", or just "Crew leader" when it starts a new rank.
   Takes a step number (`level` in Progression::standing() and lib/xp.js). */

import { t } from './i18n';
import { DEFAULT_XP_RULES, gradeOf } from './xp';

/** "Firefighter 2" */
export function rankTitle(level, rules = DEFAULT_XP_RULES) {
    const g = gradeOf(level, rules ?? DEFAULT_XP_RULES);
    return t('progress.rankGrade', { rank: t(`progress.rank.${g.rankKey}`), grade: g.grade });
}

/** What the bar is filling towards: "Firefighter 3", or "Crew leader" for a new rank. */
export function nextRankTitle(level, rules = DEFAULT_XP_RULES) {
    const g = gradeOf(level + 1, rules ?? DEFAULT_XP_RULES);
    return g.grade === 1 ? t(`progress.rank.${g.rankKey}`) : rankTitle(level + 1, rules);
}
