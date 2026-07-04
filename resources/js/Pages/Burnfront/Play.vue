<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { buildAdj, cellName, fmtClock, validate } from '@/lib/burnfront-engine';
import LoadingVeil from './LoadingVeil.vue';
import SiteBar from '@/Components/SiteBar.vue';

const props = defineProps({
    mode: { type: String, required: true }, // 'endless' | 'daily' | 'archive'
    difficulties: { type: Object, default: () => ({}) },
    difficulty: { type: String, default: '' },
    authenticated: { type: Boolean, default: false },
    archivePuzzle: { type: Object, default: null }, // set for mode 'archive': a past daily incident this account already solved
});

const isDaily = computed(() => props.mode === 'daily');
const isArchive = computed(() => props.mode === 'archive');

const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const diff = ref(props.difficulty);

const crumbText = computed(() => {
    if (isDaily.value) return 'Daily incident';
    if (isArchive.value) return 'Case history · review';
    const label = props.difficulties[diff.value]?.label;
    return label ? `Endless · ${label}` : 'Endless';
});
const game = ref(null); /* {n, adj, spark, R, C, N, clueMap, clueIdx, clueVal, clues, difficulty, name, blurb, timed, token} */
const marks = ref([]); /* 0 none, 1 break, 2 dot */
const hintSafe = ref([]); /* per-cell: this firebreak was auto-placed by a hint and still stands */
const wrongCells = ref([]); /* per-cell: flagged as an incorrect firebreak by the last hint check */
const cellStyle = ref([]); /* per-cell burn animation style, set on win */
const burnt = ref([]); /* per-cell "burn replay" flag, set on win */
const revealedMinute = ref([]); /* per-cell revealed arrival time text, set on win */

const locked = ref(true);
const hinting = ref(false);
const boardDone = ref(false);
const veilVisible = ref(false);
const clockText = ref('0:00');
const statusMessage = ref('');
const bannerVisible = ref(false);
const finalTimeText = ref('0:00');
const voided = ref(false); /* true once the board was revealed via the "Solve" cheat button */

const dailyDate = ref(null);
const dailyScorePosted = ref(false);
const leaderboard = ref([]);
const hintsUsedThisRun = ref(0);
const personalBestNote = ref('');

const undoStack = reactive([]);
let marksVersion = 0;
let genToken = 0;
let startAt = 0;
let clockTimer = null;
let statusTimer = null;
let winTimer = null;

const placedCount = computed(() => marks.value.reduce((n, m) => n + (m === 1 ? 1 : 0), 0));
const breakCountText = computed(() => (game.value ? `${placedCount.value}/${game.value.N}` : '0/0'));
const overBudget = computed(() => game.value !== null && placedCount.value > game.value.N);
const undoDisabled = computed(() => locked.value || undoStack.length === 0);
const resetDisabled = computed(() => locked.value);
const hintDisabled = computed(() => locked.value || hinting.value);
const solveDisabled = computed(() => locked.value || !game.value || hinting.value);

function stopClock() {
    if (clockTimer) {
        clearInterval(clockTimer);
        clockTimer = null;
    }
}
function startClock() {
    stopClock();
    startAt = Date.now();
    clockText.value = '0:00';
    clockTimer = setInterval(() => {
        clockText.value = fmtClock(Date.now() - startAt);
    }, 1000);
}

function clearStatus() {
    clearTimeout(statusTimer);
    statusMessage.value = '';
}
function showStatus(msg) {
    statusMessage.value = msg;
    clearTimeout(statusTimer);
    statusTimer = setTimeout(() => {
        statusMessage.value = '';
    }, 3400);
}

function clearWrongCells() {
    wrongCells.value = wrongCells.value.map(() => false);
}

function cellLabel(i) {
    const g = game.value;
    if (i === g.spark) return cellName(i, g.C) + ', the spark';
    if (g.clueMap.has(i)) return cellName(i, g.C) + ', clue: burns at minute ' + g.clueMap.get(i);
    const m = marks.value[i];
    if (m === 1) {
        if (wrongCells.value[i]) return cellName(i, g.C) + ', firebreak, flagged wrong by hint';
        if (hintSafe.value[i]) return cellName(i, g.C) + ', firebreak, placed by hint';
        return cellName(i, g.C) + ', firebreak';
    }
    return cellName(i, g.C) + (m === 2 ? ', marked clear' : ', empty');
}

function cellClasses(i) {
    const g = game.value;
    const isBreak = marks.value[i] === 1;
    return {
        'bf-cell': true,
        'is-fixed': i === g.spark || g.clueMap.has(i),
        'is-spark': i === g.spark,
        'is-clue': g.clueMap.has(i),
        'is-break': isBreak,
        'is-dot': marks.value[i] === 2,
        'is-hint-wrong': isBreak && !!wrongCells.value[i],
        'is-hint-safe': isBreak && !!hintSafe.value[i] && !wrongCells.value[i],
        'is-burnt': burnt.value[i],
    };
}

function cellText(i) {
    const g = game.value;
    if (i === g.spark) return '★';
    if (g.clueMap.has(i)) return g.clueMap.get(i);
    return revealedMinute.value[i] || '';
}

function tap(i, dir) {
    if (locked.value || !game.value) return;
    if (i === game.value.spark || game.value.clueMap.has(i)) return;
    clearWrongCells();
    marksVersion++;
    const prev = marks.value[i];
    const prevHintSafe = hintSafe.value[i];
    marks.value[i] = (prev + dir + 3) % 3;
    hintSafe.value[i] = false;
    undoStack.push([[i, prev, prevHintSafe]]);
    maybeFinish(prev !== 1 && marks.value[i] === 1);
}

function undo() {
    if (locked.value || !undoStack.length) return;
    clearWrongCells();
    marksVersion++;
    const entry = undoStack.pop();
    for (const [i, prev, prevHintSafe] of entry) {
        marks.value[i] = prev;
        hintSafe.value[i] = prevHintSafe;
    }
}

function reset() {
    if (locked.value || !game.value) return;
    clearStatus();
    clearWrongCells();
    marksVersion++;
    const entry = [];
    for (let i = 0; i < marks.value.length; i++) {
        if (marks.value[i] !== 0) {
            entry.push([i, marks.value[i], hintSafe.value[i]]);
            marks.value[i] = 0;
            hintSafe.value[i] = false;
        }
    }
    if (entry.length) undoStack.push(entry);
}

function maybeFinish(justCompleted) {
    const g = game.value;
    if (placedCount.value !== g.N) return;
    const breaks = new Uint8Array(marks.value.length);
    for (let i = 0; i < marks.value.length; i++) breaks[i] = marks.value[i] === 1 ? 1 : 0;
    const d = validate(g.n, g.adj, g.spark, g.clueIdx, g.clueVal, g.N, breaks);
    if (d) {
        win(d);
    } else if (justCompleted) {
        showStatus(`All ${g.N} breaks are down, but the fire disagrees with the report. Something’s off.`);
    }
}

function win(times) {
    const g = game.value;
    locked.value = true;
    clearStatus();
    clearWrongCells();
    stopClock();
    boardDone.value = true;
    let maxT = 0;
    for (let i = 0; i < times.length; i++) if (times[i] > maxT) maxT = times[i];
    const step = reducedMotion ? 0 : Math.min(140, 1400 / Math.max(1, maxT));
    for (let i = 0; i < times.length; i++) {
        if (times[i] < 0) continue; /* firebreaks stay dark */
        const t = times[i];
        const warm = maxT ? t / maxT : 0; /* flame core early, deep ember late */
        const mix = (a, c, f) => Math.round(a + (c - a) * f);
        const f = 0.25 + 0.6 * warm; /* 0 -> mostly flame, 1 -> mostly ember */
        cellStyle.value[i] = {
            '--burn-bg': `rgb(${mix(255, 255, f)},${mix(216, 138, f)},${mix(107, 61, f)})`,
            transitionDelay: t * step + 'ms',
        };
        if (!g.clueMap.has(i) && i !== g.spark) revealedMinute.value[i] = String(t);
        burnt.value[i] = true;
    }
    const total = maxT * step + 500;
    const token = genToken;
    clearTimeout(winTimer);
    winTimer = setTimeout(() => {
        if (token !== genToken) return;
        for (let i = 0; i < cellStyle.value.length; i++) {
            if (cellStyle.value[i]) cellStyle.value[i] = { ...cellStyle.value[i], transitionDelay: '0ms' };
        }
        finalTimeText.value = fmtClock(Date.now() - startAt);
        bannerVisible.value = true;
    }, total);

    const shaded = [];
    for (let i = 0; i < marks.value.length; i++) if (marks.value[i] === 1) shaded.push(i);

    // The /daily/play route requires an authenticated session (see
    // routes/web.php), so a signed-in user is always present here.
    if (isDaily.value && dailyDate.value) {
        submitDailyScore(shaded);
    } else if (props.mode === 'endless' && props.authenticated && diff.value !== 'custom' && g.timed) {
        submitEndlessScore(shaded, Date.now() - startAt);
    }
}

/* Paints an already-known solution onto the board with no stagger and no
   score submission — used when the player reopens a daily incident they've
   already posted a verified time for, and by solvePuzzle()'s cheat button.
   The shaded cells come straight from the server (BurnfrontController@daily
   / @solve rederive them by pure deduction; every incident has exactly one
   valid placement), so this never needs the player's own past submission.
   Callers are responsible for clearing any of the player's own marks first
   — this only ever adds shaded cells, it never removes a wrong one. */
function revealSolution(shaded) {
    const g = game.value;
    for (const i of shaded) marks.value[i] = 1;
    const breaks = new Uint8Array(marks.value.length);
    for (let i = 0; i < marks.value.length; i++) breaks[i] = marks.value[i] === 1 ? 1 : 0;
    const times = validate(g.n, g.adj, g.spark, g.clueIdx, g.clueVal, g.N, breaks);
    if (!times) return;
    let maxT = 0;
    for (let i = 0; i < times.length; i++) if (times[i] > maxT) maxT = times[i];
    for (let i = 0; i < times.length; i++) {
        if (times[i] < 0) continue; /* firebreaks stay dark */
        const t = times[i];
        const warm = maxT ? t / maxT : 0;
        const mix = (a, c, f) => Math.round(a + (c - a) * f);
        const f = 0.25 + 0.6 * warm;
        cellStyle.value[i] = {
            '--burn-bg': `rgb(${mix(255, 255, f)},${mix(216, 138, f)},${mix(107, 61, f)})`,
            transitionDelay: '0ms',
        };
        if (!g.clueMap.has(i) && i !== g.spark) revealedMinute.value[i] = String(t);
        burnt.value[i] = true;
    }
}

/* The 'custom' tier isn't a fixed dictionary entry — its rows/cols/breaks
   came from the player's own setup form and travel down as the 'custom'
   entry of the `difficulties` prop (see BurnfrontController::endlessPlay()).
   Every request that names a difficulty needs to carry that grid back to
   the server, since /puzzle and /hint can't otherwise know what a custom
   grid even is. */
function difficultyQuery(difficulty) {
    const params = { difficulty };
    if (difficulty === 'custom') {
        const custom = props.difficulties.custom;
        params.rows = custom.rows;
        params.cols = custom.cols;
        params.breaks = custom.breaks;
    }
    return params;
}

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : null;
}

async function fetchLeaderboard() {
    try {
        const resp = await fetch('/daily/leaderboard', { headers: { Accept: 'application/json' } });
        if (!resp.ok) return;
        const data = await resp.json();
        leaderboard.value = data.entries;
    } catch (e) {
        /* leaderboard is a nice-to-have; a failed fetch just leaves the panel empty */
    }
}

/* Posts a server-verified completion time for today's daily incident. The
   server never trusts the client's clock or board — it replays `shaded`
   against the actual engine and measures elapsed time from this account's
   own bound start (set the first time /daily was fetched while signed in;
   see BurnfrontController@daily), not from anything sent here. */
async function submitDailyScore(shaded) {
    if (dailyScorePosted.value) return;
    dailyScorePosted.value = true;
    try {
        const resp = await fetch('/daily/score', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ token: game.value.token, shaded }),
        });
        if (resp.ok) {
            const data = await resp.json();
            if (typeof data.hints_used === 'number') hintsUsedThisRun.value = data.hints_used;
            fetchLeaderboard();
        } else if (resp.status === 409) {
            fetchLeaderboard();
        } else {
            dailyScorePosted.value = false;
        }
    } catch (e) {
        dailyScorePosted.value = false;
    }
}

/* Records a personal-best attempt for a named endless tier. Unlike the
   daily incident, there's no server-bound start time here — time_ms is the
   client's own clock, trusted for this personal-record feature — but the
   submitted board is still independently replayed against the actual
   engine server-side before any time is recorded (see
   BurnfrontController::submitEndlessScore()). Best-effort: a failed
   request just means this run's personal-best bookkeeping is skipped. */
async function submitEndlessScore(shaded, timeMs) {
    try {
        const resp = await fetch('/endless/score', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                difficulty: diff.value,
                spark: game.value.spark,
                clues: game.value.clues,
                shaded,
                time_ms: Math.round(timeMs),
            }),
        });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.improved) personalBestNote.value = 'New personal best for this tier.';
    } catch (e) {
        /* personal bests are a nice-to-have; a failed post just skips the record */
    }
}

async function newGame() {
    const token = ++genToken;
    locked.value = true;
    dailyScorePosted.value = false;
    hintsUsedThisRun.value = 0;
    personalBestNote.value = '';
    stopClock();
    clearStatus();
    veilVisible.value = true;
    bannerVisible.value = false;
    voided.value = false;
    try {
        const resp = await fetch('/puzzle?' + new URLSearchParams(difficultyQuery(diff.value)));
        if (!resp.ok) throw new Error('puzzle request failed');
        const p = await resp.json();
        if (token !== genToken) return; /* superseded by a newer request */
        const n = p.rows * p.cols;
        const timed = props.difficulties[diff.value]?.timed !== false;
        game.value = reactive({
            n,
            adj: buildAdj(p.rows, p.cols),
            spark: p.spark,
            R: p.rows,
            C: p.cols,
            N: p.breaks,
            clueMap: new Map(p.clues),
            clueIdx: p.clues.map((cv) => cv[0]),
            clueVal: p.clues.map((cv) => cv[1]),
            clues: p.clues,
            difficulty: diff.value,
            name: p.name,
            blurb: p.blurb,
            timed,
        });
        marks.value = new Array(n).fill(0);
        hintSafe.value = new Array(n).fill(false);
        wrongCells.value = new Array(n).fill(false);
        cellStyle.value = new Array(n).fill(null);
        burnt.value = new Array(n).fill(false);
        revealedMinute.value = new Array(n).fill('');
        undoStack.length = 0;
        boardDone.value = false;
        locked.value = false;
        if (timed) startClock();
    } catch (e) {
        if (token === genToken) {
            /* Restore whatever board was already up rather than leaving it
               locked forever: tap/undo/reset all bail out while locked. */
            locked.value = false;
        }
        showStatus("Couldn't reach the incident desk. Try again.");
    } finally {
        if (token === genToken) veilVisible.value = false;
    }
}

/* Loads the shared daily incident, seeded from today's date so every player
   sees the same board (see PuzzleService::generateDaily). This route is
   only reachable while signed in (see routes/web.php), so the server's own
   "already scored" verdict is always authoritative here. */
async function loadDaily() {
    const token = ++genToken;
    locked.value = true;
    dailyScorePosted.value = false;
    hintsUsedThisRun.value = 0;
    personalBestNote.value = '';
    stopClock();
    clearStatus();
    veilVisible.value = true;
    bannerVisible.value = false;
    voided.value = false;
    try {
        const resp = await fetch('/daily');
        if (!resp.ok) throw new Error('daily request failed');
        const p = await resp.json();
        if (token !== genToken) return;
        const n = p.rows * p.cols;
        game.value = reactive({
            n,
            adj: buildAdj(p.rows, p.cols),
            spark: p.spark,
            R: p.rows,
            C: p.cols,
            N: p.breaks,
            clueMap: new Map(p.clues),
            clueIdx: p.clues.map((cv) => cv[0]),
            clueVal: p.clues.map((cv) => cv[1]),
            clues: p.clues,
            difficulty: p.difficulty,
            name: p.name,
            blurb: p.blurb,
            timed: true,
            token: p.token,
        });
        marks.value = new Array(n).fill(0);
        hintSafe.value = new Array(n).fill(false);
        wrongCells.value = new Array(n).fill(false);
        cellStyle.value = new Array(n).fill(null);
        burnt.value = new Array(n).fill(false);
        revealedMinute.value = new Array(n).fill('');
        undoStack.length = 0;
        dailyDate.value = p.date;
        fetchLeaderboard();

        if (p.alreadyScored) {
            dailyScorePosted.value = true;
            locked.value = true;
            boardDone.value = true;
            if (p.solution) revealSolution(p.solution);
            finalTimeText.value = fmtClock(p.scoreTimeMs);
            hintsUsedThisRun.value = p.hintsUsed ?? 0;
            bannerVisible.value = true;
        } else {
            boardDone.value = false;
            locked.value = false;
            startClock();
        }
    } catch (e) {
        if (token === genToken) locked.value = false;
        showStatus("Couldn't reach the incident desk. Try again.");
    } finally {
        if (token === genToken) veilVisible.value = false;
    }
}

/* Asks the incident desk for one forced deduction given the clues, plus
   whichever breaks and clear-ground dots are already placed — never the
   full solution. Purely server-side: the client has no deduction solver,
   only the local validator used at completion. Both the puzzle token and
   a marks version are captured before the request goes out and rechecked
   after, so a reply for a superseded puzzle or a stale board (the player
   tapped, undid, reset, or started a new fire while waiting) is dropped
   instead of being painted onto whatever's on screen now.

   The server only ever surfaces a forced *firebreak* (it silently works
   through any "stays clear" deductions on its own) — so a hint just places
   the cell itself, tinted green, instead of pointing at it with text. A
   contradiction instead flags whichever already-placed firebreaks the
   server can pin the blame on, tinted red — no status text needed either
   way, the board says it directly. */
async function requestHint() {
    if (locked.value || !game.value || hinting.value) return;
    hinting.value = true;
    const token = genToken;
    const version = marksVersion;
    try {
        const shaded = [];
        const open = [];
        for (let i = 0; i < marks.value.length; i++) {
            if (marks.value[i] === 1) shaded.push(i);
            else if (marks.value[i] === 2) open.push(i);
        }
        const qs = new URLSearchParams({
            ...difficultyQuery(game.value.difficulty),
            spark: String(game.value.spark),
            clues: JSON.stringify(game.value.clues),
            shaded: JSON.stringify(shaded),
            open: JSON.stringify(open),
        });
        const resp = await fetch('/hint?' + qs.toString());
        if (!resp.ok) throw new Error('hint request failed');
        const data = await resp.json();
        if (token !== genToken || version !== marksVersion) return; /* superseded */
        clearWrongCells();
        if (data.status === 'forced') {
            const cell = data.cell;
            marksVersion++;
            const prev = marks.value[cell];
            const prevHintSafe = hintSafe.value[cell];
            marks.value[cell] = 1;
            hintSafe.value[cell] = true;
            hintsUsedThisRun.value++;
            undoStack.push([[cell, prev, prevHintSafe]]);
            maybeFinish(prev !== 1);
        } else if (data.status === 'contradiction') {
            for (const cell of data.wrong ?? []) wrongCells.value[cell] = true;
        }
    } catch (e) {
        if (token === genToken && version === marksVersion) showStatus("Couldn't reach the incident desk. Try again.");
    } finally {
        hinting.value = false;
    }
}

/* Cheat button: reveals the full solution instead of one forced deduction.
   Voids the run rather than scoring it — the board locks and the clock
   stops, but win()'s daily-score submission never runs (revealSolution()
   only paints marks/cellStyle, it doesn't call win()), so a solved-for-you
   board can never post a verified daily time on its own. The server also
   never trusts this client-side void: solving a daily incident is recorded
   server-side too (see BurnfrontController::solve()/voidDailyScore()), so a
   request straight to /solve followed by a hand-crafted /daily/score POST
   still can't post a score.

   Bumping marksVersion here (disabled.value already keeps Solve unclickable
   while a hint is in flight, via hintDisabled/solveDisabled both checking
   hinting.value) is the actual guard against a hint request that was
   already in flight *before* Solve was clicked: without it, that hint's
   captured marksVersion would still match once its response finally
   arrived, so it would sail past requestHint()'s staleness check and
   re-place a cell — and since Solve already left the board fully correct,
   that can trip maybeFinish() into calling win() a second time. */
async function solvePuzzle() {
    if (locked.value || !game.value) return;
    if (!window.confirm("Reveal the full solution? Your time will be voided and this run won't be saved.")) return;
    const token = genToken;
    const n = game.value.n;
    try {
        const qs = new URLSearchParams({
            ...difficultyQuery(game.value.difficulty),
            spark: String(game.value.spark),
            clues: JSON.stringify(game.value.clues),
        });
        const resp = await fetch('/solve?' + qs.toString());
        if (!resp.ok) throw new Error('solve request failed');
        const data = await resp.json();
        if (token !== genToken) return;
        marksVersion++;
        stopClock();
        clearStatus();
        undoStack.length = 0;
        marks.value = new Array(n).fill(0);
        hintSafe.value = new Array(n).fill(false);
        wrongCells.value = new Array(n).fill(false);
        cellStyle.value = new Array(n).fill(null);
        burnt.value = new Array(n).fill(false);
        revealedMinute.value = new Array(n).fill('');
        locked.value = true;
        boardDone.value = true;
        voided.value = true;
        revealSolution(data.solution);
        bannerVisible.value = true;
    } catch (e) {
        showStatus("Couldn't reach the incident desk. Try again.");
    }
}

/* Read-only replay of a past daily incident this account already holds a
   verified time for (BurnfrontController::dailyHistoryPlay() only ever
   hands one of these back), passed down as a plain prop rather than
   fetched — there's nothing left to generate or time, just a board to
   paint straight into its solved state. */
function loadArchive() {
    const p = props.archivePuzzle;
    const n = p.rows * p.cols;
    game.value = reactive({
        n,
        adj: buildAdj(p.rows, p.cols),
        spark: p.spark,
        R: p.rows,
        C: p.cols,
        N: p.breaks,
        clueMap: new Map(p.clues),
        clueIdx: p.clues.map((cv) => cv[0]),
        clueVal: p.clues.map((cv) => cv[1]),
        clues: p.clues,
        difficulty: 'daily',
        name: p.name,
        blurb: p.blurb,
        timed: true,
    });
    marks.value = new Array(n).fill(0);
    hintSafe.value = new Array(n).fill(false);
    wrongCells.value = new Array(n).fill(false);
    cellStyle.value = new Array(n).fill(null);
    burnt.value = new Array(n).fill(false);
    revealedMinute.value = new Array(n).fill('');
    undoStack.length = 0;
    dailyDate.value = p.date;
    hintsUsedThisRun.value = p.hintsUsed ?? 0;
    revealSolution(p.solution);
    locked.value = true;
    boardDone.value = true;
    dailyScorePosted.value = true;
    finalTimeText.value = fmtClock(p.timeMs);
    bannerVisible.value = true;
}

/* A short, Wordle-style recap of this run for the player to paste
   elsewhere — never anything the server hasn't already told the client
   (name/blurb, difficulty label, time, hint count), so there's nothing new
   to validate here. */
const shareText = computed(() => {
    const g = game.value;
    if (!g) return '';
    const tierLabel = isDaily.value || isArchive.value ? 'Daily incident' : (props.difficulties[diff.value]?.label ?? 'Endless');
    const lines = [`Burnfront — ${g.name || 'Unnamed incident'}`, tierLabel];

    if (voided.value) {
        lines.push('Solved (answer revealed, not scored)');
    } else if (g.timed) {
        lines.push(`Contained in ${finalTimeText.value}`);
        lines.push(
            hintsUsedThisRun.value === 0
                ? 'Clean reconstruction — no hints'
                : `${hintsUsedThisRun.value} hint${hintsUsedThisRun.value === 1 ? '' : 's'} used`
        );
    } else {
        lines.push('Every clue burns on time');
    }

    return lines.join('\n');
});

const copyFeedback = ref('');
let copyFeedbackTimer = null;

async function copyReport() {
    try {
        await navigator.clipboard.writeText(shareText.value);
        copyFeedback.value = 'Copied.';
    } catch (e) {
        copyFeedback.value = "Couldn't copy.";
    }
    clearTimeout(copyFeedbackTimer);
    copyFeedbackTimer = setTimeout(() => {
        copyFeedback.value = '';
    }, 2400);
}

onBeforeUnmount(() => {
    stopClock();
    clearTimeout(statusTimer);
    clearTimeout(winTimer);
    clearTimeout(copyFeedbackTimer);
});

if (isDaily.value) {
    loadDaily();
} else if (isArchive.value) {
    loadArchive();
} else {
    newGame();
}
</script>

<template>
    <Head title="Burnfront" />

    <main class="mx-auto flex min-h-dvh max-w-[640px] flex-col gap-2.5 px-4 pt-3 pb-4">
        <SiteBar :back="{ href: '/', text: 'Menu' }" :crumb="crumbText" />

        <section class="flex min-h-0 flex-1 flex-col" aria-label="Puzzle board">
            <p v-if="game && game.name" class="text-sm text-ash">
                <span class="font-medium text-paper">{{ game.name }}</span> — {{ game.blurb }}
            </p>

            <div v-if="!isArchive" class="mt-2.5 flex flex-wrap items-center gap-2">
                <template v-if="mode === 'endless'">
                    <Link href="/endless" class="bf-btn">Change difficulty</Link>
                    <button type="button" class="bf-btn bf-btn-primary" @click="newGame">New fire</button>
                </template>
                <button type="button" class="bf-btn" :disabled="hintDisabled" @click="requestHint">Hint</button>
                <button type="button" class="bf-btn" :disabled="undoDisabled" @click="undo">Undo</button>
                <button type="button" class="bf-btn" :disabled="resetDisabled" @click="reset">Reset</button>
                <button type="button" class="bf-btn" :disabled="solveDisabled" @click="solvePuzzle">Solve</button>
                <div class="ml-auto flex gap-2">
                    <div class="bf-chip" :class="{ 'is-over': overBudget }">
                        <span class="bf-chip-key">Breaks</span>
                        <span class="bf-chip-value">{{ breakCountText }}</span>
                    </div>
                    <div v-if="!game || game.timed" class="bf-chip">
                        <span class="bf-chip-key">Time</span>
                        <span class="bf-chip-value">{{ clockText }}</span>
                    </div>
                </div>
            </div>

            <div class="relative mt-2.5 flex min-h-0 flex-1 items-center justify-center">
                <div
                    v-if="game"
                    class="bf-board-grid"
                    :class="{ 'is-done': boardDone }"
                    :style="{ '--cols': game.C, gridTemplateColumns: `repeat(${game.C},1fr)` }"
                    aria-label="Burnfront grid"
                >
                    <button
                        v-for="i in game.n"
                        :key="i - 1"
                        type="button"
                        :class="cellClasses(i - 1)"
                        :style="cellStyle[i - 1] || {}"
                        :aria-label="cellLabel(i - 1)"
                        @click="tap(i - 1, 1)"
                        @contextmenu.prevent="tap(i - 1, -1)"
                    >
                        <span class="bf-cell-minute">{{ cellText(i - 1) }}</span>
                    </button>
                </div>
                <LoadingVeil :visible="veilVisible" />
            </div>

            <div class="mt-2.5 flex min-h-9 flex-col gap-1.5">
                <div v-if="bannerVisible" class="flex flex-col gap-1.5">
                    <span class="bf-banner-headline">{{ voided ? 'SOLVED' : 'FIRE MAPPED' }}</span>
                    <p v-if="voided" class="text-sm text-ash">Answer revealed — time voided, this run wasn&rsquo;t saved.</p>
                    <template v-else>
                        <p v-if="game.timed" class="text-sm text-ash">
                            Contained in <span class="tabular-nums text-paper">{{ finalTimeText }}</span> — every clue burns
                            on time.
                        </p>
                        <p v-else class="text-sm text-ash">Every clue burns on time.</p>
                        <p v-if="game.timed" class="text-[12.5px] text-ash-dim">
                            {{
                                hintsUsedThisRun === 0
                                    ? 'Clean reconstruction — no hints borrowed.'
                                    : `${hintsUsedThisRun} hint${hintsUsedThisRun === 1 ? '' : 's'} used.`
                            }}
                        </p>
                        <p v-if="personalBestNote" class="text-[12.5px] text-flame">{{ personalBestNote }}</p>
                    </template>
                    <div class="flex items-center gap-2">
                        <button type="button" class="bf-btn" @click="copyReport">Copy report</button>
                        <span v-if="copyFeedback" class="text-[12px] text-ash-dim">{{ copyFeedback }}</span>
                    </div>
                </div>
                <p v-else-if="statusMessage" class="bf-status" role="status">{{ statusMessage }}</p>
                <p v-else class="max-w-[60ch] text-[13px] text-ash-dim">
                    Tap a cell to dig a firebreak &middot; tap again for a clear-ground dot &middot; a third tap erases.
                    New here? <Link href="/how-to" class="text-ember hover:text-flame">See how it works</Link>.
                </p>
            </div>

            <div
                v-if="isDaily && leaderboard.length"
                class="mt-1 flex flex-col gap-1.5 rounded-md border border-line p-3.5"
                aria-label="Today's fastest"
            >
                <h3 class="text-[11px] tracking-[.14em] text-ash-dim uppercase">Today&rsquo;s fastest</h3>
                <ol class="flex flex-col gap-1 text-sm text-ash">
                    <li v-for="entry in leaderboard" :key="entry.rank" class="flex justify-between gap-3 tabular-nums">
                        <span>
                            {{ entry.rank }}. {{ entry.name }}
                            <span v-if="entry.hints_used === 0" class="ml-1 text-[10px] tracking-[.08em] text-ember uppercase"
                                >clean</span
                            >
                        </span>
                        <span class="text-paper">{{ fmtClock(entry.time_ms) }}</span>
                    </li>
                </ol>
            </div>
        </section>
    </main>
</template>
