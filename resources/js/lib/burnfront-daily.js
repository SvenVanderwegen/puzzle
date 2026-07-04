/* Client-side "already solved today" record for the daily incident. This is
   a UX convenience only — the leaderboard's source of truth is the server's
   own per-user-per-date uniqueness constraint (see BurnfrontController@submitDailyScore).
   Guarded against private-mode browsers throwing on localStorage access. */

function dailyKey(date) {
    return `burnfront:daily:${date}`;
}

export function getDailyRecord(date) {
    try {
        const raw = localStorage.getItem(dailyKey(date));
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

export function markDailySolved(date, timeMs) {
    try {
        localStorage.setItem(dailyKey(date), JSON.stringify({ solvedAt: new Date().toISOString(), timeMs }));
    } catch {
        /* private-mode storage denial: nothing to do, the solve still counts server-side */
    }
}
