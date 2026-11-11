/* Weekly goal, points and level, fed by every solved fire.

   A signed-in player's numbers come from the server (PlayerProgress::forUser).
   A guest has no server record, so their solves and points are logged here
   in localStorage and the same rules are applied (lib/xp.js mirrors the
   server's maths). The rules themselves always come from the server
   (props.progressRules, from config/burnfront.php), so they are defined in
   one place.

   Storage can be missing or throw (private mode, blocked site data): every
   read and write is guarded and a guest then simply starts at level 1. */

import { DEFAULT_XP_RULES, reward, standing } from './xp';

const LOG_KEY = 'burnfront.solves.v1';
const GUEST_DAILY_KEY = 'burnfront.guestDaily.v1';
const RECENT_DAYS = 14;

export const DEFAULT_RULES = { weekGoal: 5, xp: DEFAULT_XP_RULES };

function readJson(key) {
    try {
        const raw = window.localStorage.getItem(key);
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
}

function writeJson(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
        /* storage unavailable: progress just isn't kept on this device */
    }
}

/** Monday 00:00 UTC of the week `now` falls in, matching the server's week. */
export function weekStart(now = new Date()) {
    const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
    const day = d.getUTCDay(); // 0 = Sunday
    d.setUTCDate(d.getUTCDate() - ((day + 6) % 7));
    return d;
}

function readLog() {
    const log = readJson(LOG_KEY);
    if (!log || typeof log !== 'object') return { total: 0, recent: [], xp: 0 };
    return {
        total: Number.isFinite(log.total) ? log.total : 0,
        recent: Array.isArray(log.recent) ? log.recent.filter(Number.isFinite) : [],
        /* A log from before points existed: count its fires as plain small fires. */
        xp: Number.isFinite(log.xp) ? Math.max(0, log.xp) : (Number.isFinite(log.total) ? log.total : 0) * DEFAULT_XP_RULES.fireBase,
    };
}

/**
 * Logs one solved fire on this device and gives the points for it. Call it
 * once per verified solve. `facts` is {rows, cols, breaks, daily, hintsUsed}
 * (hintsUsed null where hints are not counted), or null for a fire that
 * counts for the week but gives no points. Returns the same reward the
 * server sends a signed-in player: {items, total, xpBefore, xpAfter}.
 */
export function recordLocalSolve(facts, rules = DEFAULT_RULES, now = Date.now()) {
    const xpRules = rules?.xp ?? DEFAULT_XP_RULES;
    const goal = rules?.weekGoal ?? DEFAULT_RULES.weekGoal;
    const log = readLog();
    const cutoff = now - RECENT_DAYS * 86400000;
    log.total += 1;
    log.recent = [...log.recent.filter((at) => at >= cutoff), now];

    const since = weekStart(new Date(now)).getTime();
    const weekSolved = log.recent.filter((at) => at >= since).length;
    const earned = facts ? reward({ ...facts, weekReached: weekSolved === goal }, xpRules) : { items: [], total: 0 };

    const xpBefore = log.xp;
    log.xp = xpBefore + earned.total;
    writeJson(LOG_KEY, log);

    return { ...earned, xpBefore, xpAfter: log.xp };
}

/** This device's progress, in the same shape the server sends. */
export function localProgress(rules = DEFAULT_RULES, now = new Date()) {
    const log = readLog();
    const since = weekStart(now).getTime();
    return {
        weekSolved: log.recent.filter((at) => at >= since).length,
        weekGoal: rules?.weekGoal ?? DEFAULT_RULES.weekGoal,
        totalSolved: log.total,
        ...standing(log.xp, rules?.xp ?? DEFAULT_XP_RULES),
    };
}

/** Server progress when there is one (signed in), else this device's. */
export function resolveProgress(serverProgress, rules) {
    return serverProgress ?? localProgress(rules ?? DEFAULT_RULES);
}

/* A guest's daily result also lives server-side in their session, but a
   session ends after a couple of hours. This keeps "today's fire is
   contained" on the home screen for the rest of the day. */
export function rememberGuestDaily(date, result) {
    writeJson(GUEST_DAILY_KEY, { date, ...result });
}

export function guestDailyFor(date) {
    const saved = readJson(GUEST_DAILY_KEY);
    return saved && saved.date === date ? saved : null;
}
