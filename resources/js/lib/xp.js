/* Points and ranks: the client's copy of the maths in
   app/Support/Burnfront/Progression.php. The server is the source of truth
   for a signed-in player; this copy exists so the result screen can count
   the points up step by step, and so a guest (no server record) gets the
   same numbers from their own device. The rules always come from the server
   (the progressRules.xp prop); DEFAULT_XP_RULES only mirrors
   config/burnfront.php for when a page has none. Keep both files in step. */

export const DEFAULT_XP_RULES = {
    levelBase: 60,
    levelStep: 20,
    levelCap: 500,
    rankLevels: [1, 5, 9, 13, 17, 21, 25],
    ranks: ['trainee', 'firefighter', 'crewLeader', 'captain', 'chief', 'commander', 'legend'],
    fireBase: 20,
    firePerSide: 10,
    fireMin: 10,
    fireMax: 70,
    noHints: 10,
    daily: 20,
    streakPerDay: 5,
    streakMaxDays: 5,
    weekGoal: 50,
};

/** Points needed to go from `level` to the next one. */
export function xpToNext(level, rules = DEFAULT_XP_RULES) {
    return Math.min(rules.levelCap, rules.levelBase + rules.levelStep * (Math.max(1, level) - 1));
}

export function rankIndexForLevel(level, rules = DEFAULT_XP_RULES) {
    let index = 0;
    rules.rankLevels.forEach((from, i) => {
        if (level >= from) index = i;
    });
    return index;
}

/** The rank and the grade inside it ("Firefighter 2") that step `level` of the points ladder is shown as. */
export function gradeOf(level, rules = DEFAULT_XP_RULES) {
    const rank = rankIndexForLevel(level, rules);
    return { rank, rankKey: rules.ranks[rank], grade: level - rules.rankLevels[rank] + 1 };
}

/** Same shape as Progression::standing(). `level` is a step on the points ladder (shown as rank and grade), not a campaign level. `xp` may be a fraction while a bar is filling. */
export function standing(xp, rules = DEFAULT_XP_RULES) {
    const total = Math.max(0, Number.isFinite(xp) ? xp : 0);
    let level = 1;
    let remaining = total;
    let size = xpToNext(1, rules);
    while (remaining >= size) {
        remaining -= size;
        level++;
        size = xpToNext(level, rules);
    }
    const rank = rankIndexForLevel(level, rules);
    return {
        xp: total,
        level,
        intoLevel: remaining,
        levelSize: size,
        toGo: size - remaining,
        rank,
        rankKey: rules.ranks[rank],
        grade: level - rules.rankLevels[rank] + 1,
        nextRankLevel: rules.rankLevels[rank + 1] ?? null,
    };
}

export function fireXp(rows, cols, rules = DEFAULT_XP_RULES) {
    const side = Math.max(rows, cols);
    return Math.max(rules.fireMin, Math.min(rules.fireMax, rules.fireBase + rules.firePerSide * (side - 5)));
}

/** Same lines as Progression::reward(): {items: [{key, xp, n?}], total}. */
export function reward(facts, rules = DEFAULT_XP_RULES) {
    const hints = facts.hintsUsed ?? null;
    const breaks = facts.breaks ?? 0;
    const allHinted = hints !== null && breaks > 0 && hints >= breaks;

    const items = [{ key: 'fire', xp: allHinted ? 0 : fireXp(facts.rows, facts.cols, rules) }];
    if (hints === 0 && rules.noHints > 0) items.push({ key: 'noHints', xp: rules.noHints });
    if (facts.daily && rules.daily > 0) items.push({ key: 'daily', xp: rules.daily });
    const streak = facts.streak ?? 0;
    if (streak >= 2 && rules.streakPerDay > 0) {
        items.push({ key: 'streak', xp: rules.streakPerDay * Math.min(streak, rules.streakMaxDays), n: streak });
    }
    if (facts.weekReached && rules.weekGoal > 0) items.push({ key: 'week', xp: rules.weekGoal });

    return { items, total: items.reduce((sum, item) => sum + item.xp, 0) };
}

/** 0..1 along the heat ramp for a rank: yellow for the first, violet for the last. */
export function rankStep(rank, rules = DEFAULT_XP_RULES) {
    const last = Math.max(1, rules.ranks.length - 1);
    return Math.max(0, Math.min(1, rank / last));
}
