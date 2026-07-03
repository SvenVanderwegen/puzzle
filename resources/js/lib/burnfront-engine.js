/* Client-side validator for a Burnfront board. Puzzle generation is
   server-authoritative (see BurnfrontController@puzzle) — this module only
   builds adjacency and validates a player's marking locally, so solving
   gives instant feedback without a round trip. Ported from the reference
   prototype's fb-engine, same as app/Support/Burnfront/Engine.php. */

export const COLS = 'ABCDEFGHIJ';

export function buildAdj(R, C) {
    const adj = [];
    for (let r = 0; r < R; r++) {
        for (let c = 0; c < C; c++) {
            const a = [];
            if (r > 0) a.push((r - 1) * C + c);
            if (r < R - 1) a.push((r + 1) * C + c);
            if (c > 0) a.push(r * C + c - 1);
            if (c < C - 1) a.push(r * C + c + 1);
            adj.push(a);
        }
    }
    return adj;
}

/** BFS from spark over cells where pass(i) is true; -1 = unreachable. */
export function bfs(n, adj, spark, pass) {
    const dist = new Int32Array(n).fill(-1);
    if (!pass(spark)) return dist;
    dist[spark] = 0;
    const q = new Int32Array(n);
    q[0] = spark;
    let head = 0,
        tail = 1;
    while (head < tail) {
        const x = q[head++];
        const ax = adj[x];
        for (let k = 0; k < ax.length; k++) {
            const y = ax[k];
            if (dist[y] < 0 && pass(y)) {
                dist[y] = dist[x] + 1;
                q[tail++] = y;
            }
        }
    }
    return dist;
}

/**
 * Validate a player's complete marking. breaks: Uint8Array (1 = shaded).
 * Doesn't need the solution — recomputes burn times from the marking and
 * checks them against the clues, same as the server would. Returns the
 * distance array on success, false otherwise.
 */
export function validate(n, adj, spark, clueIdx, clueVal, N, breaks) {
    let nSh = 0;
    for (let i = 0; i < n; i++) if (breaks[i]) nSh++;
    if (nSh !== N) return false;
    if (breaks[spark]) return false;
    for (let k = 0; k < clueIdx.length; k++) if (breaks[clueIdx[k]]) return false;
    const d = bfs(n, adj, spark, (i) => !breaks[i]);
    for (let i = 0; i < n; i++) if (!breaks[i] && d[i] < 0) return false;
    for (let k = 0; k < clueIdx.length; k++) if (d[clueIdx[k]] !== clueVal[k]) return false;
    return d;
}

export function cellName(i, C) {
    return COLS[i % C] + (Math.floor(i / C) + 1);
}

export function fmtClock(ms) {
    const s = Math.max(0, Math.floor(ms / 1000));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}
