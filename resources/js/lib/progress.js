/* Weekly goal and player level, fed by every solved fire.

   A signed-in player's numbers come from the server (PlayerProgress::forUser).
   A guest has no server record, so their solves are logged here in
   localStorage and the same rules are applied. The rules themselves always
   come from the server (props.progressRules, from config/burnfront.php), so
   the curve is defined in one place; levelForSolves() below mirrors
   PlayerProgress::levelForSolves() and must stay in step with it.

   Storage can be missing or throw (private mode, blocked site data): every
   read and write is guarded and a guest then simply starts at level 1. */

const LOG_KEY = 'burnfront.solves.v1';
const GUEST_DAILY_KEY = 'burnfront.guestDaily.v1';
const RECENT_DAYS = 14;

export const DEFAULT_RULES = { weekGoal: 5, levelBase: 3, levelStep: 1 };

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

/** Same curve as PlayerProgress::levelForSolves(). */
export function levelForSolves(totalSolved, rules = DEFAULT_RULES) {
    const base = Math.max(1, rules.levelBase ?? DEFAULT_RULES.levelBase);
    const step = Math.max(0, rules.levelStep ?? DEFAULT_RULES.levelStep);
    let level = 1;
    let remaining = Math.max(0, totalSolved | 0);
    let size = base;
    while (remaining >= size) {
        remaining -= size;
        level++;
        size = base + (level - 1) * step;
    }
    return { level, intoLevel: remaining, levelSize: size, toGo: size - remaining };
}

function readLog() {
    const log = readJson(LOG_KEY);
    if (!log || typeof log !== 'object') return { total: 0, recent: [] };
    return {
        total: Number.isFinite(log.total) ? log.total : 0,
        recent: Array.isArray(log.recent) ? log.recent.filter(Number.isFinite) : [],
    };
}

/** Logs one solved fire on this device. Call it once per verified solve. */
export function recordLocalSolve(now = Date.now()) {
    const log = readLog();
    const cutoff = now - RECENT_DAYS * 86400000;
    log.total += 1;
    log.recent = [...log.recent.filter((at) => at >= cutoff), now];
    writeJson(LOG_KEY, log);
}

/** This device's progress, in the same shape the server sends. */
export function localProgress(rules = DEFAULT_RULES, now = new Date()) {
    const log = readLog();
    const since = weekStart(now).getTime();
    return {
        weekSolved: log.recent.filter((at) => at >= since).length,
        weekGoal: rules.weekGoal ?? DEFAULT_RULES.weekGoal,
        totalSolved: log.total,
        ...levelForSolves(log.total, rules),
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
