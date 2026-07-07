<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { buildAdj, cellName, COLS, fmtClock, validate } from '@/lib/burnfront-engine';
import LoadingVeil from './LoadingVeil.vue';
import SiteBar from '@/Components/SiteBar.vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';
import RubberStamp from '@/Components/RubberStamp.vue';
import BurnReplayPayoff from '@/Components/BurnReplayPayoff.vue';

const props = defineProps({
    mode: { type: String, required: true }, // 'endless' | 'daily' | 'archive' | 'campaign'
    difficulties: { type: Object, default: () => ({}) },
    difficulty: { type: String, default: '' },
    authenticated: { type: Boolean, default: false },
    archivePuzzle: { type: Object, default: null }, // set for mode 'archive': a past daily incident this account already solved
    levelConfig: { type: Object, default: null }, // set for mode 'campaign': this account's current level, from CampaignService::levelConfig()
});

const isDaily = computed(() => props.mode === 'daily');
const isArchive = computed(() => props.mode === 'archive');
const isCampaign = computed(() => props.mode === 'campaign');

const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const diff = ref(props.difficulty);

const crumbText = computed(() => {
    if (isDaily.value) return 'Daily incident';
    if (isArchive.value) return 'Case history · review';
    if (isCampaign.value) return props.levelConfig ? `Campaign · ${props.levelConfig.label}` : 'Campaign';
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
const burnTimes = ref([]); /* per-cell burn arrival minutes (validate()'s distance array) for the payoff replay hero, set on win/reveal */

const locked = ref(true);
const hinting = ref(false);
const boardDone = ref(false);
const veilVisible = ref(false);
const clockText = ref('0:00');
const statusMessage = ref('');
const bannerVisible = ref(false);
const finalTimeText = ref('0:00');
const voided = ref(false); /* true once the board was revealed via the "Solve" cheat button */
/* true only when this board was completed just now, in this session (a real
   win() or a solvePuzzle() reveal) — false for a board opened already-solved
   (an archive replay, or reopening today's already-scored daily). Gates
   whether the board is swapped out for the "Contained" payoff screen: a
   review of a past incident should keep showing the board, not hide it
   behind a payoff for a solve that didn't just happen. */
const justSolved = ref(false);

const dailyDate = ref(null);
const dailyScorePosted = ref(false);
const leaderboard = ref([]);
const hintsUsedThisRun = ref(0);
const personalBestNote = ref('');
const campaignResult = ref(null); /* {xpAwarded, level, leveledUp, xpIntoLevel, xpToNextLevel, chapterLabel, campaignComplete}, set on a campaign win */

const undoStack = reactive([]);
/* Chronological, append-only log of every mark/undo/reset/hint action this
   run (never popped, unlike undoStack) — sent alongside the final board so
   a completed game can be reviewed/replayed later (see
   BurnfrontController::submitDailyScore()/submitEndlessScore()). Purely a
   client-reported record: it's never replayed against the engine and never
   affects scoring. */
const moveLog = reactive([]);
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

/* Mono subline under the incident name — "Hotshot · 7×7 · Endless" — read
   straight off game/mode state, purely cosmetic. */
const boardSubline = computed(() => {
    const g = game.value;
    if (!g) return '';
    const dims = `${g.R}×${g.C}`;
    if (isDaily.value) return `Daily · ${dims}`;
    if (isArchive.value) return `Daily · ${dims} · Review`;
    if (isCampaign.value) return `Campaign · ${props.levelConfig?.chapterLabel ?? ''}`.trim();
    const label = props.difficulties[diff.value]?.label ?? '';
    return `${label.replace(/\s*\d+×\d+$/, '')} · ${dims} · Endless`;
});

/* A dossier-flavor case number for the header/payoff — decorative only, no
   backend record behind it. Deterministic from the board's own identifying
   data so the same incident always reads the same case number. */
function hashCode(str) {
    let h = 0;
    for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) | 0;
    return Math.abs(h);
}
const caseNumber = computed(() => {
    const g = game.value;
    if (!g) return '';
    if (isDaily.value || isArchive.value) {
        const digits = (dailyDate.value ?? '').replace(/\D/g, '').slice(2);
        return `BF-${digits || '0000'}`;
    }
    return `BF-${1000 + (hashCode(`${g.spark}:${g.R}x${g.C}:${g.clues.length}`) % 9000)}`;
});

/* Discrepancy stamp on the plot sheet: shown while the last hint check left
   a placed firebreak flagged wrong, cleared the moment the player acts on
   it (clearWrongCells() runs on every tap/undo/reset/hint). */
const hasDiscrepancy = computed(() => !boardDone.value && wrongCells.value.some(Boolean));

/* Just-earned XP segment (the flame dashed overlay) on the campaign payoff's
   rank bar — approximated from xpAwarded since the server only reports the
   run's *resulting* standing, not the bar's starting position. */
const xpAfterPct = computed(() => {
    const r = campaignResult.value;
    if (!r || !r.xpToNextLevel) return 100;
    return Math.min(100, Math.round((r.xpIntoLevel / r.xpToNextLevel) * 100));
});
const xpBeforePct = computed(() => {
    const r = campaignResult.value;
    if (!r || !r.xpToNextLevel) return xpAfterPct.value;
    const before = r.xpIntoLevel - r.xpAwarded;
    if (r.leveledUp || before < 0) return 0;
    return Math.min(100, Math.round((before / r.xpToNextLevel) * 100));
});

function stopClock() {
    if (clockTimer) {
        clearInterval(clockTimer);
        clockTimer = null;
    }
}
/* startAtValue seeds the clock from a resumed save (see persistProgress()/
   restoreEndlessGame() below) instead of always starting now. It's an
   absolute timestamp, not a relative elapsed duration: persistProgress()
   only runs on mark-changing actions, so a relative "elapsed so far" value
   would go stale the moment the player just sits looking at the board and
   then refreshes — every second of that idle gap would silently vanish
   from the resumed clock. Seeding startAt itself instead means the clock
   (and whatever time_ms eventually gets submitted) always reflects true
   wall-clock time since the board was first opened, idle gaps included. */
function startClock(startAtValue = Date.now()) {
    stopClock();
    startAt = startAtValue;
    clockText.value = fmtClock(Date.now() - startAt);
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

/* localStorage persistence for an in-progress board, so a refresh, an
   accidental back/navigation, or a closed tab doesn't silently throw away
   marks the player already placed — previously nothing survived a reload.
   Every read/write is best-effort (private browsing, a full quota, or
   storage disabled entirely all just mean resume silently doesn't happen,
   never a hard error). Keyed per difficulty (custom grids further by their
   own rows/cols/breaks) so switching tiers can never restore the wrong
   board; the daily incident gets one shared slot instead, since there's
   only ever one daily board in flight at a time. */
const DAILY_PROGRESS_KEY = 'burnfront:save:daily:v1';

function endlessProgressKey(difficulty) {
    if (difficulty === 'custom') {
        const c = props.difficulties.custom;
        return `burnfront:save:endless:custom:${c?.rows}x${c?.cols}x${c?.breaks}:v1`;
    }
    return `burnfront:save:endless:${difficulty}:v1`;
}

function currentProgressKey() {
    const g = game.value;
    if (!g) return null;
    return isDaily.value ? DAILY_PROGRESS_KEY : endlessProgressKey(g.difficulty);
}

function readProgress(key) {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
}

function clearProgress(key) {
    try {
        localStorage.removeItem(key);
    } catch (e) {
        /* best-effort */
    }
}

/* Called after every mark-changing action (tap/undo/reset/hint) — cheap
   enough to write on every change rather than debounce, since a saved
   board is at most a few hundred small integers. Skipped once boardDone is
   set (win()/solvePuzzle() clear the save outright instead — there's
   nothing left to resume). */
function persistProgress() {
    const g = game.value;
    if (!g || isArchive.value || boardDone.value) return;
    const key = currentProgressKey();
    if (!key) return;
    const payload = {
        spark: g.spark,
        clues: g.clues,
        rows: g.R,
        cols: g.C,
        breaks: g.N,
        difficulty: g.difficulty,
        name: g.name,
        blurb: g.blurb,
        timed: g.timed,
        date: dailyDate.value,
        marks: marks.value,
        hintSafe: hintSafe.value,
        undoStack: undoStack.slice(),
        hintsUsedThisRun: hintsUsedThisRun.value,
        startAt: clockTimer ? startAt : null,
    };
    try {
        localStorage.setItem(key, JSON.stringify(payload));
    } catch (e) {
        /* best-effort */
    }
}

/* A saved endless board only matches the difficulty the player is looking
   at right now — for 'custom' that also means the same grid, since the
   rows/cols/breaks are player-chosen and vary per save (see
   endlessProgressKey()); the key already encodes them, but the payload is
   double-checked too rather than trusted blindly off a key string. */
function endlessSaveMatches(saved) {
    if (!saved || saved.difficulty !== diff.value) return false;
    if (diff.value !== 'custom') return true;
    const c = props.difficulties.custom;
    return !!c && saved.rows === c.rows && saved.cols === c.cols && saved.breaks === c.breaks;
}

/* generateDaily() is deterministic per date, so a save for today should
   always match today's freshly-fetched board — checked anyway rather than
   trusted on the date alone, same caution the rest of this file applies to
   anything read back out of client-side storage. */
function dailySaveMatches(saved, p) {
    return saved.date === p.date && saved.spark === p.spark && JSON.stringify(saved.clues) === JSON.stringify(p.clues);
}

/* Rebuilds `game` and every per-cell array straight from a save, with no
   /puzzle round trip — the saved spark/clues/breaks already are the
   incident, so there's nothing left to fetch. */
function restoreEndlessGame(saved) {
    const n = saved.rows * saved.cols;
    game.value = reactive({
        n,
        adj: buildAdj(saved.rows, saved.cols),
        spark: saved.spark,
        R: saved.rows,
        C: saved.cols,
        N: saved.breaks,
        clueMap: new Map(saved.clues),
        clueIdx: saved.clues.map((cv) => cv[0]),
        clueVal: saved.clues.map((cv) => cv[1]),
        clues: saved.clues,
        difficulty: saved.difficulty,
        name: saved.name,
        blurb: saved.blurb,
        timed: saved.timed,
    });
    marks.value = saved.marks;
    hintSafe.value = saved.hintSafe;
    wrongCells.value = new Array(n).fill(false);
    cellStyle.value = new Array(n).fill(null);
    burnt.value = new Array(n).fill(false);
    revealedMinute.value = new Array(n).fill('');
    undoStack.length = 0;
    undoStack.push(...(saved.undoStack ?? []));
    focusedIndex.value = 0;
    hintsUsedThisRun.value = saved.hintsUsedThisRun ?? 0;
    personalBestNote.value = '';
    dailyScorePosted.value = false;
    voided.value = false;
    justSolved.value = false;
    boardDone.value = false;
    bannerVisible.value = false;
    veilVisible.value = false;
    locked.value = false;
    if (saved.timed) startClock(saved.startAt || Date.now());
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
    moveLog.push({ t: Date.now() - startAt, type: 'mark', cell: i, prev, value: marks.value[i], prevHintSafe });
    persistProgress();
    maybeFinish(prev !== 1 && marks.value[i] === 1);
}

/* Roving tabindex for the board: exactly one cell is a tab stop at a time,
   and arrow keys move both DOM focus and that stop between cells (a plain
   Tab order over up to 64 buttons is unusable). cellEls isn't reactive —
   it's only ever used for imperative .focus() calls, never read for
   rendering — so a plain array is enough, and stale entries left behind by
   a smaller subsequent board are simply never indexed into. */
const focusedIndex = ref(0);
let cellEls = [];
function setCellEl(i, el) {
    cellEls[i] = el;
}
function focusCell(i) {
    cellEls[i]?.focus();
}

/* Arrow keys move focus by grid position; Backspace is the keyboard
   equivalent of the right-click/long-press "reverse tap" (dir -1), since a
   keyboard has no separate secondary-click gesture. Enter/Space already
   reach tap() for free — they're native <button> activation, which fires a
   'click' event same as a mouse tap. */
function onCellKeydown(e, i) {
    const g = game.value;
    if (!g) return;
    const col = i % g.C;
    switch (e.key) {
        case 'ArrowUp':
            e.preventDefault();
            focusCell(i - g.C);
            break;
        case 'ArrowDown':
            e.preventDefault();
            focusCell(i + g.C);
            break;
        case 'ArrowLeft':
            e.preventDefault();
            if (col > 0) focusCell(i - 1);
            break;
        case 'ArrowRight':
            e.preventDefault();
            if (col < g.C - 1) focusCell(i + 1);
            break;
        case 'Home':
            e.preventDefault();
            focusCell(i - col);
            break;
        case 'End':
            e.preventDefault();
            focusCell(i - col + g.C - 1);
            break;
        case 'Backspace':
            e.preventDefault();
            tap(i, -1);
            break;
    }
}

/* Single-letter shortcuts for the board toolbar, mirrored in the buttons'
   title tooltips below. Ignored while a modifier key is held (so Ctrl+R
   still refreshes the page) and while focus is in a form field (there are
   none on this page today, but this keeps the listener safe if one is ever
   added). Attached on window, not the board, so a shortcut works no matter
   which cell — or nothing — currently has focus. */
function onGlobalKeydown(e) {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const tag = document.activeElement?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    switch (e.key.toLowerCase()) {
        case 'u':
            if (!undoDisabled.value) undo();
            break;
        case 'r':
            if (!resetDisabled.value) reset();
            break;
        case 'h':
            if (!hintDisabled.value) requestHint();
            break;
        case 's':
            if (!solveDisabled.value) solvePuzzle();
            break;
    }
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
    moveLog.push({
        t: Date.now() - startAt,
        type: 'undo',
        restores: entry.map(([i, prev, prevHintSafe]) => [i, prev, prevHintSafe]),
    });
    persistProgress();
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
    if (entry.length) {
        undoStack.push(entry);
        moveLog.push({ t: Date.now() - startAt, type: 'reset', cleared: entry });
    }
    persistProgress();
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
    clearProgress(currentProgressKey()); // solved — nothing left to resume
    locked.value = true;
    clearStatus();
    clearWrongCells();
    stopClock();
    boardDone.value = true;
    burnTimes.value = Array.from(times); /* hand the solved incident to the payoff replay hero (BurnReplayPayoff) */
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
        justSolved.value = true;
        bannerVisible.value = true;
    }, total);

    const shaded = [];
    for (let i = 0; i < marks.value.length; i++) if (marks.value[i] === 1) shaded.push(i);

    // The /daily/play route requires an authenticated session (see
    // routes/web.php), so a signed-in user is always present here.
    if (isDaily.value && dailyDate.value) {
        submitDailyScore(shaded);
    } else if (props.mode === 'endless' && props.authenticated && diff.value !== 'custom') {
        // Untimed tiers (Cold Case) still get recorded — just with no
        // time_ms, since there's no clock to keep a best against (see
        // BurnfrontController::submitEndlessScore()). That's what lets a
        // solve there ever bump solved_count at all.
        submitEndlessScore(shaded, g.timed ? Date.now() - startAt : null);
    } else if (isCampaign.value) {
        submitCampaignScore(shaded);
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
    burnTimes.value = Array.from(times); /* also feeds the payoff replay hero on the voided "Solve" path */
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
    /* Campaign never sends a level or the board itself — hint()/solve() recover
       both from the signed run token puzzle() issued (see
       CampaignService::decodeRun()), so a request can't probe a fabricated board. */
    if (difficulty === 'campaign') return { difficulty: 'campaign', token: game.value?.token ?? '' };
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
            body: JSON.stringify({ token: game.value.token, shaded, moves: moveLog }),
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

/* Records a solved incident for a named endless tier. Unlike the daily
   incident, there's no server-bound start time here — time_ms is the
   client's own clock, trusted for this personal-record feature — but the
   submitted board is still independently replayed against the actual
   engine server-side before any time is recorded (see
   BurnfrontController::submitEndlessScore()). timeMs is null for an
   untimed tier (Cold Case): there's no clock to trust or keep a best
   against, so the field is left out entirely rather than sent as a made-up
   0 — the server only ever bumps solved_count for those. Best-effort: a
   failed request just means this run's personal-best bookkeeping is
   skipped. */
async function submitEndlessScore(shaded, timeMs) {
    try {
        const body = {
            difficulty: diff.value,
            spark: game.value.spark,
            clues: game.value.clues,
            shaded,
            moves: moveLog,
        };
        if (timeMs !== null) body.time_ms = Math.round(timeMs);
        const resp = await fetch('/endless/score', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body),
        });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.improved) personalBestNote.value = 'New personal best for this tier.';
    } catch (e) {
        /* personal bests are a nice-to-have; a failed post just skips the record */
    }
}

/* Converts a verified solve into XP. Sends only the run token puzzle()
   issued plus the shaded cells — the server recovers level/spark/clues from
   the token and reads this run's actual hint count from its own cache
   (see CampaignController::submitScore()), rather than trusting anything
   this client reports, so hintsUsedThisRun below is purely this run's own
   local display copy, never what the server scores against. Best-effort
   like submitEndlessScore(): a failed post just means this run's XP/
   level-up banner is skipped, nothing is lost since the server is the only
   source of truth for total_xp. */
async function submitCampaignScore(shaded) {
    try {
        const resp = await fetch('/campaign/score', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                token: game.value.token,
                shaded,
            }),
        });
        if (!resp.ok) return;
        campaignResult.value = await resp.json();
    } catch (e) {
        /* XP is a nice-to-have; a failed post just skips this run's banner */
    }
}

/* fresh=false (only ever passed by the initial mount, below) tries to
   resume a saved in-progress board for the current difficulty before
   generating anything new — see persistProgress(). fresh=true (the "New
   fire" button) always starts a new incident and drops whichever save was
   sitting there for this difficulty, since the player is explicitly asking
   to abandon it. */
async function newGame(fresh = true) {
    const key = endlessProgressKey(diff.value);
    if (fresh) {
        clearProgress(key);
    } else {
        const saved = readProgress(key);
        if (saved && endlessSaveMatches(saved)) {
            restoreEndlessGame(saved);
            return;
        }
    }

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
    justSolved.value = false;
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
        moveLog.length = 0;
        focusedIndex.value = 0;
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

/* Loads a fresh puzzle at this account's current campaign level. Unlike
   newGame(), there's no difficulty to pick — the level is earned via XP
   (CampaignController::puzzle() derives it from CampaignProfile), so this
   never sends a level and the board is always whatever the player has
   reached, win or lose the last attempt. */
async function loadCampaign() {
    const token = ++genToken;
    locked.value = true;
    hintsUsedThisRun.value = 0;
    campaignResult.value = null;
    stopClock();
    clearStatus();
    veilVisible.value = true;
    bannerVisible.value = false;
    voided.value = false;
    justSolved.value = false;
    try {
        const resp = await fetch('/campaign/puzzle');
        if (!resp.ok) throw new Error('campaign puzzle request failed');
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
            difficulty: 'campaign',
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
        boardDone.value = false;
        locked.value = false;
        startClock();
    } catch (e) {
        if (token === genToken) locked.value = false;
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
    justSolved.value = false;
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
        moveLog.length = 0;
        focusedIndex.value = 0;
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
            // A save only ever resumes today's own board — dailySaveMatches()
            // checks the date plus the spark/clues themselves, so a save left
            // over from a previous day (or a client clock skewed a day off)
            // can never paint onto a board it wasn't generated for.
            const saved = readProgress(DAILY_PROGRESS_KEY);
            if (saved && dailySaveMatches(saved, p)) {
                marks.value = saved.marks;
                hintSafe.value = saved.hintSafe;
                undoStack.length = 0;
                undoStack.push(...(saved.undoStack ?? []));
                hintsUsedThisRun.value = saved.hintsUsedThisRun ?? 0;
                startClock(saved.startAt || Date.now());
            } else {
                startClock();
            }
            boardDone.value = false;
            locked.value = false;
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
            moveLog.push({ t: Date.now() - startAt, type: 'hint', cell, prev, value: 1, prevHintSafe });
            persistProgress();
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
        clearProgress(currentProgressKey()); // revealed — nothing left to resume
        marksVersion++;
        stopClock();
        clearStatus();
        undoStack.length = 0;
        moveLog.length = 0;
        marks.value = new Array(n).fill(0);
        hintSafe.value = new Array(n).fill(false);
        wrongCells.value = new Array(n).fill(false);
        cellStyle.value = new Array(n).fill(null);
        burnt.value = new Array(n).fill(false);
        revealedMinute.value = new Array(n).fill('');
        locked.value = true;
        boardDone.value = true;
        voided.value = true;
        justSolved.value = true;
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
    moveLog.length = 0;
    focusedIndex.value = 0;
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
    const tierLabel = isDaily.value || isArchive.value
        ? 'Daily incident'
        : isCampaign.value
          ? `Campaign · ${props.levelConfig?.label ?? ''}`.trim()
          : (props.difficulties[diff.value]?.label ?? 'Endless');
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

    if (isCampaign.value && campaignResult.value) {
        lines.push(`+${campaignResult.value.xpAwarded} XP`);
        if (campaignResult.value.leveledUp) lines.push(`Leveled up to ${campaignResult.value.level} · ${campaignResult.value.chapterLabel}`);
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

onMounted(() => window.addEventListener('keydown', onGlobalKeydown));

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onGlobalKeydown);
    stopClock();
    clearTimeout(statusTimer);
    clearTimeout(winTimer);
    clearTimeout(copyFeedbackTimer);
});

if (isDaily.value) {
    loadDaily();
} else if (isArchive.value) {
    loadArchive();
} else if (isCampaign.value) {
    loadCampaign();
} else {
    newGame(false);
}
</script>

<template>
    <Head title="Burnfront" />

    <main class="mx-auto flex min-h-dvh max-w-[640px] flex-col gap-2.5 px-4 pt-3 pb-4">
        <SiteBar :back="{ href: '/', text: 'Menu' }" :crumb="crumbText" />

        <!-- ============ THE PLOT SHEET (in progress / review) ============ -->
        <section v-if="!(bannerVisible && justSolved)" class="flex min-h-0 flex-1 flex-col" aria-label="Puzzle board">
            <div v-if="game && game.name" class="mt-1 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="truncate font-staatliches text-[28px] leading-none tracking-[.02em] text-stock">{{ game.name }}</h2>
                    <p class="mt-1 font-mono text-[10px] tracking-[.1em] text-ash-dim uppercase">{{ boardSubline }}</p>
                </div>
                <div class="shrink-0 text-right font-mono text-[9.5px] leading-[1.5] text-ash-dim">
                    CASE<br /><span class="text-ash">{{ caseNumber }}</span>
                </div>
            </div>
            <p v-if="game && game.blurb" class="mt-1 text-sm text-ash">{{ game.blurb }}</p>

            <div v-if="!isArchive" class="mt-2.5 flex flex-wrap items-center gap-2">
                <template v-if="mode === 'endless'">
                    <Link href="/endless" class="bf-btn">Change difficulty</Link>
                    <button type="button" class="bf-btn bf-btn-primary" @click="newGame(true)">New fire</button>
                </template>
                <template v-else-if="mode === 'campaign'">
                    <Link href="/campaign" class="bf-btn">Case file</Link>
                    <button type="button" class="bf-btn bf-btn-primary" @click="loadCampaign">New fire</button>
                </template>
            </div>

            <div v-if="!isArchive && game" class="mt-2.5 flex gap-2">
                <div class="bf-chip" :class="{ 'is-over': overBudget }">
                    <span class="bf-chip-key">Breaks</span>
                    <span class="bf-chip-value">{{ breakCountText }}</span>
                </div>
                <div v-if="game.timed" class="bf-chip">
                    <span class="bf-chip-key">Time</span>
                    <span class="bf-chip-value">{{ clockText }}</span>
                </div>
                <div class="bf-chip">
                    <span class="bf-chip-key">Hints</span>
                    <span class="bf-chip-value">{{ hintsUsedThisRun }}</span>
                </div>
            </div>

            <div class="relative mt-2.5 flex min-h-0 flex-1 items-center justify-center">
                <div v-if="game" class="bf-plotsheet">
                    <div class="bf-plotsheet-grid">
                        <div></div>
                        <div class="bf-rail-cols" :style="{ gridTemplateColumns: `repeat(${game.C},1fr)` }">
                            <span v-for="c in game.C" :key="`col${c}`" class="bf-rail-label">{{ COLS[c - 1] }}</span>
                        </div>
                        <div class="bf-rail-rows" :style="{ gridTemplateRows: `repeat(${game.R},1fr)` }">
                            <span v-for="r in game.R" :key="`row${r}`" class="bf-rail-label">{{ r }}</span>
                        </div>
                        <div
                            class="bf-board-grid"
                            :class="{ 'is-done': boardDone }"
                            :style="{ '--cols': game.C, gridTemplateColumns: `repeat(${game.C},1fr)` }"
                            aria-label="Burnfront grid"
                        >
                            <button
                                v-for="i in game.n"
                                :key="i - 1"
                                :ref="(el) => setCellEl(i - 1, el)"
                                type="button"
                                :class="cellClasses(i - 1)"
                                :style="cellStyle[i - 1] || {}"
                                :aria-label="cellLabel(i - 1)"
                                :tabindex="i - 1 === focusedIndex ? 0 : -1"
                                @click="tap(i - 1, 1)"
                                @contextmenu.prevent="tap(i - 1, -1)"
                                @keydown="onCellKeydown($event, i - 1)"
                                @focus="focusedIndex = i - 1"
                            >
                                <span class="bf-cell-minute">
                                    <FlameGlyph v-if="i - 1 === game.spark" glow />
                                    <template v-else>{{ cellText(i - 1) }}</template>
                                </span>
                            </button>
                        </div>
                    </div>
                    <RubberStamp v-if="hasDiscrepancy" tone="void" size="sm" :rotate="-8" class="bf-discrepancy">Discrepancy</RubberStamp>
                </div>
                <LoadingVeil :visible="veilVisible" />
            </div>

            <div class="mt-2.5 flex min-h-9 flex-col gap-1.5">
                <div v-if="bannerVisible && !justSolved" class="flex flex-col gap-1.5">
                    <p class="text-sm text-ash">
                        <span v-if="voided">Answer revealed — time voided, this run wasn&rsquo;t saved.</span>
                        <span v-else-if="game.timed">
                            Contained in <span class="tabular-nums text-stock">{{ finalTimeText }}</span> — every clue burns on
                            time.
                        </span>
                        <span v-else>Every clue burns on time.</span>
                    </p>
                    <p v-if="!voided && game.timed" class="text-[12.5px] text-ash-dim">
                        {{
                            hintsUsedThisRun === 0
                                ? 'Clean reconstruction — no hints borrowed.'
                                : `${hintsUsedThisRun} hint${hintsUsedThisRun === 1 ? '' : 's'} used.`
                        }}
                    </p>
                    <div class="flex items-center gap-2">
                        <button type="button" class="bf-btn" @click="copyReport">Share</button>
                        <span v-if="copyFeedback" class="text-[12px] text-ash-dim">{{ copyFeedback }}</span>
                    </div>
                </div>
                <p v-else-if="statusMessage" class="bf-status" role="status">{{ statusMessage }}</p>
                <p v-else class="max-w-[60ch] text-[13px] text-ash-dim">
                    Tap a cell to dig a firebreak &middot; tap again for a clear-ground dot &middot; a third tap erases.
                    New here? <Link href="/how-to" class="text-ember hover:text-flame">See how it works</Link>.
                </p>
                <p v-if="!isArchive" class="max-w-[60ch] text-[11.5px] text-ash-dim">
                    Keyboard: arrow keys move, Backspace reverses a cell, U undo &middot; R reset &middot; H hint &middot; S
                    solve.
                </p>
            </div>

            <div v-if="!isArchive" class="mt-1 flex gap-2">
                <button type="button" class="bf-btn bf-btn-outline flex-1" title="Hint (H)" :disabled="hintDisabled" @click="requestHint">
                    Hint
                </button>
                <button type="button" class="bf-btn flex-1" title="Undo (U)" :disabled="undoDisabled" @click="undo">Undo</button>
                <button type="button" class="bf-btn flex-1" title="Reset (R)" :disabled="resetDisabled" @click="reset">Reset</button>
                <button type="button" class="bf-btn flex-1" title="Solve (S)" :disabled="solveDisabled" @click="solvePuzzle">Solve</button>
            </div>
        </section>

        <!-- ============ CONTAINED — solve payoff ============ -->
        <section v-else class="flex flex-col gap-4" aria-label="Incident contained">
            <p class="text-center font-mono text-[10px] tracking-[.2em] text-ash-dim uppercase">Reconstruction complete</p>

            <div class="bf-payoff-hero h-[210px] sm:h-[260px]">
                <BurnReplayPayoff
                    :rows="game.R"
                    :cols="game.C"
                    :spark="game.spark"
                    :times="burnTimes"
                    :clues="game.clues"
                    :label="voided ? 'Solved' : 'Contained'"
                />
            </div>

            <p class="text-center text-[13px] text-ash">
                <span v-if="voided">Answer revealed — time voided, this run wasn&rsquo;t saved.</span>
                <span v-else-if="game.timed">Every timestamp reconciles. One reconstruction, no guessing</span>
                <span v-else>Every clue burns on time</span>
                <template v-if="!voided"> — filed to <span class="font-mono text-ash">{{ caseNumber }}</span>.</template>
            </p>

            <div v-if="!voided" class="flex gap-2">
                <div class="bf-stat-tile">
                    <span class="bf-stat-value">{{ game.timed ? finalTimeText : '—' }}</span>
                    <span class="bf-stat-label">Time</span>
                </div>
                <div class="bf-stat-tile">
                    <span class="bf-stat-value">{{ breakCountText }}</span>
                    <span class="bf-stat-label">Breaks</span>
                </div>
                <div class="bf-stat-tile" :class="{ 'is-good': hintsUsedThisRun === 0 }">
                    <span class="bf-stat-value" :class="{ 'is-good': hintsUsedThisRun === 0 }">{{ hintsUsedThisRun }}</span>
                    <span class="bf-stat-label">Hints</span>
                </div>
            </div>

            <p v-if="personalBestNote" class="text-center text-[12.5px] text-flame">{{ personalBestNote }}</p>

            <div v-if="isCampaign && campaignResult" class="bf-xp-card">
                <div class="flex items-baseline justify-between font-mono text-[9.5px] tracking-[.12em] text-ash-dim uppercase">
                    <span>Rank &middot; {{ campaignResult.chapterLabel }}</span>
                    <span class="font-bold text-ember-hi">+{{ campaignResult.xpAwarded }} XP</span>
                </div>
                <div class="bf-xp-track">
                    <div class="bf-xp-fill" :style="{ width: xpAfterPct + '%' }"></div>
                    <div
                        v-if="xpAfterPct > xpBeforePct"
                        class="bf-xp-fill-new"
                        :style="{ left: xpBeforePct + '%', width: xpAfterPct - xpBeforePct + '%' }"
                    ></div>
                </div>
                <div class="flex justify-between font-mono text-[9px] text-ash-dim">
                    <span>{{ campaignResult.xpIntoLevel }}/{{ campaignResult.xpToNextLevel ?? campaignResult.xpIntoLevel }} XP</span>
                    <span v-if="campaignResult.xpAwarded === 0">No breaks left un-hinted</span>
                </div>
                <p v-if="campaignResult.leveledUp" class="bf-levelup text-center text-[15px] font-semibold tracking-[.06em] uppercase">
                    Level up &rarr; {{ campaignResult.level }} &middot; {{ campaignResult.chapterLabel }}
                </p>
                <p v-if="campaignResult.campaignComplete" class="text-center text-[12.5px] text-ember">
                    Every case file in the record is closed.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <template v-if="isCampaign">
                    <Link href="/campaign" class="bf-btn flex-1 text-center">Case file</Link>
                    <button type="button" class="bf-btn bf-btn-primary flex-[2]" @click="loadCampaign">Next incident</button>
                </template>
                <button v-else-if="mode === 'endless'" type="button" class="bf-btn bf-btn-primary flex-[2]" @click="newGame(true)">
                    Next incident
                </button>
                <button type="button" class="bf-btn" :class="isCampaign || mode === 'endless' ? 'flex-1' : 'flex-[2]'" @click="copyReport">
                    Share
                </button>
            </div>
            <p v-if="copyFeedback" class="text-center text-[12px] text-ash-dim">{{ copyFeedback }}</p>
        </section>

        <div
            v-if="isDaily && leaderboard.length"
            class="mt-1 flex flex-col gap-1.5 rounded-lg border border-rule-2 bg-folder p-3.5"
            aria-label="Today's fastest"
        >
            <h3 class="font-mono text-[11px] tracking-[.14em] text-ash-dim uppercase">Today&rsquo;s fastest</h3>
            <ol class="flex flex-col gap-1 text-sm text-ash">
                <li v-for="entry in leaderboard" :key="entry.rank" class="flex justify-between gap-3 tabular-nums">
                    <span>
                        {{ entry.rank }}. {{ entry.name }}
                        <span v-if="entry.hints_used === 0" class="ml-1 font-mono text-[10px] tracking-[.08em] text-ember uppercase"
                            >clean</span
                        >
                    </span>
                    <span class="text-stock">{{ fmtClock(entry.time_ms) }}</span>
                </li>
            </ol>
        </div>
    </main>
</template>
