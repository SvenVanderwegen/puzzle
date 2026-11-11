<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { bfs, buildAdj, cellName, fmtClock, validate } from '@/lib/burnfront-engine';
import LoadingVeil from './LoadingVeil.vue';
import FlameGlyph from '@/Components/FlameGlyph.vue';
import SoundToggle from '@/Components/SoundToggle.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import WeeklyProgress from '@/Components/Progress/WeeklyProgress.vue';
import { burnCurve, heatCell, heatColor } from '@/lib/heat';
import { t } from '@/lib/i18n';
import { isDark } from '@/lib/theme';
import { play } from '@/lib/sound';
import { recordLocalSolve, rememberGuestDaily } from '@/lib/progress';
import { buildShareText, shareResult } from '@/lib/share';
import { nextFireDetail } from '@/lib/next-fire';

const props = defineProps({
    mode: { type: String, required: true }, // 'endless' | 'daily' | 'archive' | 'campaign'
    difficulties: { type: Object, default: () => ({}) },
    difficulty: { type: String, default: '' },
    authenticated: { type: Boolean, default: false },
    archivePuzzle: { type: Object, default: null }, // set for mode 'archive': a past daily incident this account already solved
    levelConfig: { type: Object, default: null }, // set for mode 'campaign': this account's current level, from CampaignService::levelConfig()
    relaxed: { type: Boolean, default: false }, // quick fire from the home: no visible clock
    nextFire: { type: Object, default: null }, // NextFire::for(): where the result screen's "Next fire" goes
    progressRules: { type: Object, default: null }, // PlayerProgress::rules(), for a guest's local progress
});

const isDaily = computed(() => props.mode === 'daily');
const isArchive = computed(() => props.mode === 'archive');
const isCampaign = computed(() => props.mode === 'campaign');

const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const diff = ref(props.difficulty);

/* Campaign level number (1..20) from the level config the server sends. */
const campaignLevel = computed(() => {
    const c = props.levelConfig;
    if (!c) return null;
    return c.level ?? (c.chapterKey - 1) * 4 + c.levelInChapter;
});

/* Small label at the top of the board: which kind of fire this is. */
const modeLabel = computed(() => {
    if (isDaily.value) return t('play.mode.daily');
    if (isArchive.value) return t('play.mode.archive');
    if (isCampaign.value) return campaignLevel.value ? t('play.mode.campaignLevel', { level: campaignLevel.value }) : t('play.mode.campaign');
    const word = t(`common.difficulty.${diff.value}`);
    return t('play.mode.practice', { word });
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
const campaignResult = ref(null); /* {xpAwarded, level, leveledUp, xpIntoLevel, xpToNextLevel, chapterLabel, campaignComplete, nextFire, progress}, set on a campaign win */
/* Weekly goal / level after this solve, as the server reports it for a
   signed-in player (null for a guest: WeeklyProgress then reads the local
   log that win() just added to). */
const serverProgress = ref(null);
const dailyNumber = ref(null);
const guestDailySolved = ref(false); /* a guest just filed today's daily: offer to save it to an account */
/* Where "Next fire" goes: the campaign's answer after a campaign solve
   (the level may have just changed), else what the page was rendered with. */
const nextFireTarget = computed(() => campaignResult.value?.nextFire ?? props.nextFire);

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
const breakCountText = computed(() => (game.value ? t('play.supplyCount', { count: placedCount.value, total: game.value.N }) : ''));
const overBudget = computed(() => game.value !== null && placedCount.value > game.value.N);
const undoDisabled = computed(() => locked.value || undoStack.length === 0);
const resetDisabled = computed(() => locked.value);
const hintDisabled = computed(() => locked.value || hinting.value);
const solveDisabled = computed(() => locked.value || !game.value || hinting.value || isDaily.value);

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

function hasStartedCurrentIncident() {
    return marks.value.some((mark) => mark !== 0) || clockText.value !== '0:00';
}

function confirmIncidentReplacement() {
    return boardDone.value || !hasStartedCurrentIncident() || window.confirm(t('play.confirmNew'));
}

function replaceEndlessIncident() {
    if (confirmIncidentReplacement()) newGame(true);
}

function replaceCampaignIncident() {
    if (confirmIncidentReplacement()) loadCampaign();
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
    /* A quick fire (relaxed, no clock) never resumes a timed board, or the
       other way round. */
    const timedNow = !props.relaxed && props.difficulties[diff.value]?.timed !== false;
    if (Boolean(saved.timed) !== timedNow) return false;
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
    const where = cellName(i, g.C);
    if (i === g.spark) return t('play.cell.spark', { where });
    if (g.clueMap.has(i)) {
        const minute = g.clueMap.get(i);
        return isLate(i) ? t('play.cell.clueLate', { where, minute }) : t('play.cell.clue', { where, minute });
    }
    const m = marks.value[i];
    if (m === 1) {
        if (wrongCells.value[i]) return t('play.cell.breakWrong', { where });
        if (hintSafe.value[i]) return t('play.cell.breakHint', { where });
        return t('play.cell.break', { where });
    }
    if (burnt.value[i] && revealedMinute.value[i]) return t('play.cell.burnt', { where, minute: revealedMinute.value[i] });
    return m === 2 ? t('play.cell.dot', { where }) : t('play.cell.empty', { where });
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
        'is-dot': marks.value[i] === 2 && !burnt.value[i],
        'is-hint-wrong': isBreak && !!wrongCells.value[i],
        'is-hint-safe': isBreak && !!hintSafe.value[i] && !wrongCells.value[i],
        'is-burnt': burnt.value[i],
        'is-revealed': !!revealedMinute.value[i],
        'is-late': isLate(i),
    };
}

/* ---------- live reading of the board (display only, the rules stay in the engine) ---------- */

/* Where the fire would reach with the firebreaks placed right now. A
   firebreak can only ever delay the fire, so a number the fire already
   reaches too late (or not at all) is certainly wrong: it gets a ring and
   a "!" badge. Once every firebreak is down, any mismatch counts. */
const liveDist = computed(() => {
    const g = game.value;
    if (!g || marks.value.length !== g.n) return null;
    const m = marks.value;
    return bfs(g.n, g.adj, g.spark, (i) => m[i] !== 1);
});
function isLate(i) {
    const g = game.value;
    if (!g || boardDone.value || !g.clueMap.has(i) || !liveDist.value) return false;
    const d = liveDist.value[i];
    const want = g.clueMap.get(i);
    return d < 0 || d > want || (placedCount.value >= g.N && d !== want);
}
const anyLate = computed(() => {
    const g = game.value;
    if (!g) return false;
    return g.clueIdx.some((i) => isLate(i));
});

/* Colour scale: minute / last minute. Before the solve the biggest number
   stands in for the last minute; once solved the real last minute is used. */
const lastMinute = computed(() => {
    const g = game.value;
    if (!g) return 1;
    let m = Math.max(1, ...g.clueVal);
    for (const v of burnTimes.value) if (v > m) m = v;
    return m;
});
function cellVars(i) {
    const g = game.value;
    let minute = null;
    if (i === g.spark) minute = 0;
    else if (g.clueMap.has(i)) minute = g.clueMap.get(i);
    else if (burnt.value[i] && burnTimes.value[i] >= 0) minute = burnTimes.value[i];
    const base = cellStyle.value[i] || {};
    if (minute === null) return base;
    const c = heatCell(minute, lastMinute.value, isDark.value);
    return { ...base, '--heat-bg': c.bg, '--heat-fg': c.fg };
}

/* Minute legend under the board: one swatch per minute, a number every few. */
const legend = computed(() => {
    const last = lastMinute.value;
    const step = Math.max(1, Math.ceil(last / 4));
    return Array.from({ length: last + 1 }, (_, k) => ({
        k,
        bg: heatColor(k / last, isDark.value),
        label: k % step === 0 || k === last ? String(k) : '',
    }));
});

const pips = computed(() => {
    const g = game.value;
    if (!g) return [];
    return Array.from({ length: g.N }, (_, k) => k < placedCount.value);
});

/* The one message line under the board. It always has the same height, so
   nothing on screen moves when it changes. */
const message = computed(() => {
    const g = game.value;
    if (!g) return '';
    if (statusMessage.value) return statusMessage.value;
    if (boardDone.value) return ''; /* the fire is running through the board; the result follows */
    if (isArchive.value) return t('play.msg.archive');
    if (hasDiscrepancy.value) return t('play.msg.hintWrong');
    const count = placedCount.value;
    if (count > g.N) return t('play.msg.tooMany', { count: g.N });
    if (count === g.N) return t('play.msg.fullWrong', { count: g.N });
    if (anyLate.value) return t('play.msg.late');
    if (count === 0) return t('play.msg.start');
    const left = g.N - count;
    return left === 1 ? t('play.msg.oneLeft') : t('play.msg.left', { count: left });
});

/* ---------- solved screen ---------- */
const curve = computed(() => {
    const counts = burnCurve(burnTimes.value);
    const max = Math.max(1, ...counts);
    const last = Math.max(1, counts.length - 1);
    return counts.map((c, k) => ({ k, h: Math.max(3, Math.round((c / max) * 44)), bg: heatColor(k / last, isDark.value) }));
});
const curveBlocksText = computed(() => {
    const blocks = '▁▂▃▄▅▆▇█';
    const counts = burnCurve(burnTimes.value);
    const max = Math.max(1, ...counts);
    return counts.map((c) => blocks[Math.min(7, Math.round((c / max) * 7))]).join('');
});
const hintsText = computed(() => {
    const n = hintsUsedThisRun.value;
    return n === 0 ? t('play.solved.noHints') : n === 1 ? t('play.solved.oneHint') : t('play.solved.hints', { count: n });
});

/* The main button after a solve: the next fire. The server can name it
   (nextFire prop); otherwise each mode falls back to its own next step. */
const nextAction = computed(() => {
    const target = nextFireTarget.value;
    /* Campaign and endless load the next fire in place; the daily follows
       the server's NextFire::for() answer (campaign, or a quick fire). */
    if (target && !isCampaign.value && props.mode !== 'endless' && !isArchive.value) {
        return { href: target.href, label: t('play.solved.next'), detail: nextFireDetail(target) };
    }
    if (isCampaign.value) {
        const level = campaignResult.value?.level ?? campaignLevel.value;
        return { run: loadCampaign, label: t('play.solved.next'), detail: level ? t('play.solved.nextCampaign', { level }) : '' };
    }
    if (props.mode === 'endless') {
        const g = game.value;
        return { run: () => newGame(true), label: t('play.solved.next'), detail: g ? t('common.by', { rows: g.R, cols: g.C }) : '' };
    }
    if (isArchive.value) return { href: '/daily/history', label: t('play.solved.backToHistory'), detail: '' };
    return { href: props.authenticated ? '/campaign/play' : '/', label: t('play.solved.next'), detail: props.authenticated ? t('play.solved.campaign') : '' };
});

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
    const outcome = maybeFinish(prev !== 1 && marks.value[i] === 1);
    /* one sound per tap: the move itself, or "not right yet" when this tap
       used up (or overran) the firebreaks without containing the fire;
       a win plays the fire running instead (see win()) */
    if (outcome === 'won') return;
    if (outcome === 'wrong' || (marks.value[i] === 1 && placedCount.value > game.value.N)) play('wrong');
    else play(['clear', 'place', 'dot'][marks.value[i]]);
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
    play('clear');
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
        play('clear');
    }
    persistProgress();
}

/* Returns 'won' when this board is now solved, 'wrong' when the last
   firebreak just went down without containing the fire, else null. */
function maybeFinish(justCompleted) {
    const g = game.value;
    if (placedCount.value !== g.N) return null;
    const breaks = new Uint8Array(marks.value.length);
    for (let i = 0; i < marks.value.length; i++) breaks[i] = marks.value[i] === 1 ? 1 : 0;
    const d = validate(g.n, g.adj, g.spark, g.clueIdx, g.clueVal, g.N, breaks);
    if (d) {
        win(d);
        return 'won';
    }
    if (justCompleted) {
        showStatus(t('play.msg.fullWrong', { count: g.N }));
        return 'wrong';
    }
    return null;
}

function win(times) {
    const g = game.value;
    clearProgress(currentProgressKey()); // solved — nothing left to resume
    locked.value = true;
    clearStatus();
    clearWrongCells();
    stopClock();
    finalTimeText.value = fmtClock(Date.now() - startAt); /* the header clock stops here, not after the burn */
    boardDone.value = true;
    play('burn'); /* the fire runs through the board, then the chord when the result shows */
    burnTimes.value = Array.from(times); /* the solved fire, for the burn curve and the minute numbers */
    let maxT = 0;
    for (let i = 0; i < times.length; i++) if (times[i] > maxT) maxT = times[i];
    const step = reducedMotion ? 0 : Math.min(55, 720 / Math.max(1, maxT));
    /* colours come from the heat ramp at render time (cellVars); here only the
       order: each square lights up at its own minute */
    for (let i = 0; i < times.length; i++) {
        if (times[i] < 0) continue; /* firebreaks stay as they are */
        const minute = times[i];
        cellStyle.value[i] = { transitionDelay: minute * step + 'ms' };
        if (!g.clueMap.has(i) && i !== g.spark) revealedMinute.value[i] = String(minute);
        burnt.value[i] = true;
    }
    const total = maxT * step + (reducedMotion ? 0 : 220);
    const token = genToken;
    clearTimeout(winTimer);
    winTimer = setTimeout(() => {
        if (token !== genToken) return;
        for (let i = 0; i < cellStyle.value.length; i++) {
            if (cellStyle.value[i]) cellStyle.value[i] = { ...cellStyle.value[i], transitionDelay: '0ms' };
        }
        justSolved.value = true;
        bannerVisible.value = true;
        play('solved');
    }, total);

    const shaded = [];
    for (let i = 0; i < marks.value.length; i++) if (marks.value[i] === 1) shaded.push(i);

    // A guest has no server record: every solve feeds this device's weekly
    // goal and level instead (lib/progress.js). A signed-in player's solve
    // is counted by the server when the score below is posted.
    if (!props.authenticated) recordLocalSolve();

    // Guests can play the daily too: the server verifies their board the
    // same way and keeps the result in their session (DailyRunStore).
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
    for (let i = 0; i < times.length; i++) {
        if (times[i] < 0) continue; /* firebreaks stay as they are */
        cellStyle.value[i] = { transitionDelay: '0ms' };
        if (!g.clueMap.has(i) && i !== g.spark) revealedMinute.value[i] = String(times[i]);
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
            if (data.progress) serverProgress.value = data.progress;
            if (data.guest) {
                guestDailySolved.value = true;
                rememberGuestDaily(dailyDate.value, { timeMs: data.time_ms, hintsUsed: data.hints_used });
            }
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
        if (props.relaxed) body.relaxed = true;
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
        if (data.progress) serverProgress.value = data.progress;
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
                moves: moveLog,
            }),
        });
        if (!resp.ok) return;
        campaignResult.value = await resp.json();
        if (campaignResult.value?.progress) serverProgress.value = campaignResult.value.progress;
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
    serverProgress.value = null;
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
        const timed = !props.relaxed && props.difficulties[diff.value]?.timed !== false;
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
        showStatus(t('common.networkError'));
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
    serverProgress.value = null;
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
        moveLog.length = 0;
        boardDone.value = false;
        locked.value = false;
        startClock();
    } catch (e) {
        if (token === genToken) locked.value = false;
        showStatus(t('common.networkError'));
    } finally {
        if (token === genToken) veilVisible.value = false;
    }
}

/* Loads the shared daily incident, seeded from today's date so every player
   sees the same board (see PuzzleService::generateDaily). Open to guests:
   the server tracks a guest's run in their session, so its "already
   scored" verdict is authoritative here either way. */
async function loadDaily() {
    const token = ++genToken;
    locked.value = true;
    dailyScorePosted.value = false;
    hintsUsedThisRun.value = 0;
    personalBestNote.value = '';
    serverProgress.value = null;
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
        dailyNumber.value = p.number ?? null;
        fetchLeaderboard();

        if (p.alreadyScored) {
            dailyScorePosted.value = true;
            locked.value = true;
            boardDone.value = true;
            if (p.solution) revealSolution(p.solution);
            finalTimeText.value = fmtClock(p.scoreTimeMs);
            hintsUsedThisRun.value = p.hintsUsed ?? 0;
            guestDailySolved.value = Boolean(p.guest);
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
        showStatus(t('common.networkError'));
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
            const outcome = maybeFinish(prev !== 1);
            if (outcome !== 'won') play(outcome === 'wrong' ? 'wrong' : 'hint');
        } else if (data.status === 'contradiction') {
            for (const cell of data.wrong ?? []) wrongCells.value[cell] = true;
            play('wrong');
        }
    } catch (e) {
        if (token === genToken && version === marksVersion) showStatus(t('common.networkError'));
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
   that can trip maybeFinish() into calling win() a second time.

   The daily incident has no Solve button at all (see the button row and
   the "S solve" keyboard hint below, both gated on !isDaily) — the shared
   board is meant to be raced, not peeked at, and the server already
   voids/rejects a daily solve regardless (BurnfrontController::solve()).
   This guard just keeps that true even if something still calls this
   function directly. */
async function solvePuzzle() {
    if (locked.value || !game.value || isDaily.value) return;
    if (!window.confirm(t('play.confirmReveal'))) return;
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
        finalTimeText.value = clockText.value;
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
        showStatus(t('common.networkError'));
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

/* The spoiler-free share text (lib/share.js): fire, time, hints and the
   burn curve, never the map, so posting the daily doesn't give it away. */
const shareText = computed(() => {
    const g = game.value;
    if (!g) return '';
    return buildShareText({
        mode: props.mode,
        dailyNumber: dailyNumber.value,
        level: isCampaign.value ? campaignLevel.value : null,
        rows: g.R,
        cols: g.C,
        timeText: g.timed ? finalTimeText.value : null,
        hintsUsed: hintsUsedThisRun.value,
        revealed: voided.value,
        times: burnTimes.value,
    });
});

const copyFeedback = ref('');
let copyFeedbackTimer = null;

async function copyReport() {
    const outcome = await shareResult(shareText.value);
    copyFeedback.value = outcome === 'copied' ? t('share.copied') : outcome === 'shared' ? t('share.shared') : outcome === 'failed' ? t('share.failed') : '';
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
    <Head :title="game?.name ? `${game.name} · Burnfront` : 'Burnfront'" />

    <div class="bf-page is-narrow min-h-dvh">
        <main class="bf-play" :aria-label="t('play.boardLabel')">
            <!-- ============ header: back, what this is, the clock ============ -->
            <header class="bf-play-head">
                <Link :href="isArchive ? '/daily/history' : '/'" class="bf-icon-btn" :aria-label="t('play.back')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 5l-7 7 7 7" />
                    </svg>
                </Link>
                <span class="bf-play-mode bf-mono-label">{{ modeLabel }}</span>
                <span class="bf-play-head-end">
                    <span v-if="game && game.timed" class="bf-play-clock" role="timer" :aria-label="t('play.clockLabel')">{{
                        boardDone ? finalTimeText : clockText
                    }}</span>
                    <SoundToggle />
                    <ThemeToggle />
                </span>
            </header>

            <!-- ============ title: the fire's name ============ -->
            <!-- Everything above the board (title, tools, firebreak supply) and
                 the legend under it stay put after a solve, so the board never
                 moves: only the area below the legend turns into the result. -->
            <section v-if="game" class="bf-play-title">
                <h1>{{ game.name }}</h1>
                <p v-if="game.blurb">{{ game.blurb }}</p>
            </section>

            <div v-if="game && !isArchive && mode !== 'daily'" class="bf-play-tools">
                <template v-if="mode === 'endless'">
                    <Link href="/endless" class="bf-btn bf-btn-soft">{{ t('play.tools.otherSize') }}</Link>
                    <button type="button" class="bf-btn bf-btn-soft" @click="replaceEndlessIncident">{{ t('play.tools.newFire') }}</button>
                </template>
                <template v-else-if="mode === 'campaign'">
                    <Link href="/campaign" class="bf-btn bf-btn-soft">{{ t('play.tools.campaignMap') }}</Link>
                    <button type="button" class="bf-btn bf-btn-soft" @click="replaceCampaignIncident">{{ t('play.tools.newFire') }}</button>
                </template>
            </div>

            <!-- ============ firebreak supply ============ -->
            <div v-if="game" class="bf-supply" :class="{ 'is-over': overBudget }">
                <span>{{ t('play.supply') }}</span>
                <span class="flex items-center gap-2.5">
                    <span class="bf-supply-pips" aria-hidden="true">
                        <i v-for="(used, k) in pips" :key="k" :class="{ 'is-used': used }"></i>
                    </span>
                    <span class="bf-supply-count" aria-live="polite">{{ breakCountText }}</span>
                </span>
            </div>

            <!-- ============ the board ============ -->
            <div class="bf-board-wrap">
                <div
                    v-if="game"
                    class="bf-board-grid"
                    :class="{ 'is-done': boardDone }"
                    :style="{ '--cols': game.C, gridTemplateColumns: `repeat(${game.C}, minmax(0, 1fr))` }"
                    role="group"
                    :aria-label="t('play.gridLabel', { rows: game.R, cols: game.C })"
                >
                    <button
                        v-for="i in game.n"
                        :key="i - 1"
                        :ref="(el) => setCellEl(i - 1, el)"
                        type="button"
                        :class="cellClasses(i - 1)"
                        :style="cellVars(i - 1)"
                        :aria-label="cellLabel(i - 1)"
                        :tabindex="i - 1 === focusedIndex ? 0 : -1"
                        @click="tap(i - 1, 1)"
                        @contextmenu.prevent="tap(i - 1, -1)"
                        @keydown="onCellKeydown($event, i - 1)"
                        @focus="focusedIndex = i - 1"
                    >
                        <span class="bf-cell-minute">
                            <FlameGlyph v-if="i - 1 === game.spark" />
                            <template v-else>{{ cellText(i - 1) }}</template>
                        </span>
                        <span v-if="isLate(i - 1)" class="bf-cell-badge" aria-hidden="true">!</span>
                    </button>
                </div>
                <LoadingVeil :visible="veilVisible" />
            </div>

            <div v-if="game" class="bf-legend" aria-hidden="true">
                <span>{{ t('common.minutes') }}</span>
                <ol :style="{ gridTemplateColumns: `repeat(${legend.length}, minmax(0, 1fr))` }">
                    <li v-for="l in legend" :key="l.k"><i :style="{ background: l.bg }"></i><b>{{ l.label }}</b></li>
                </ol>
            </div>

            <!-- ============ while playing: message, controls ============ -->
            <template v-if="game && !bannerVisible">
                <p class="bf-message" role="status" aria-live="polite">{{ message }}</p>

                <div v-if="!isArchive" class="mt-auto flex flex-col">
                    <div class="bf-controls">
                        <button type="button" class="bf-btn" :title="t('play.controls.undoKey')" :disabled="undoDisabled" @click="undo">
                            {{ t('play.controls.undo') }}
                        </button>
                        <button type="button" class="bf-btn" :title="t('play.controls.hintKey')" :disabled="hintDisabled" @click="requestHint">
                            {{ t('play.controls.hint') }}
                        </button>
                        <button type="button" class="bf-btn" :title="t('play.controls.resetKey')" :disabled="resetDisabled" @click="reset">
                            {{ t('play.controls.reset') }}
                        </button>
                    </div>
                    <button v-if="!isDaily" type="button" class="bf-reveal-action mt-1" :disabled="solveDisabled" @click="solvePuzzle">
                        {{ t('play.controls.reveal') }}
                    </button>
                    <p class="bf-keyboard-help">{{ isDaily ? t('play.keyboardDaily') : t('play.keyboard') }}</p>
                </div>
            </template>

            <!-- ============ solved ============ -->
            <section v-else-if="game" class="bf-solved" :aria-label="t('play.solved.title')">
                <div class="bf-solved-head">
                    <h2>{{ voided ? t('play.solved.revealedTitle') : t('play.solved.title') }}</h2>
                    <div v-if="burnTimes.length" class="bf-curve" role="img" :aria-label="t('play.solved.curveLabel')">
                        <i v-for="b in curve" :key="b.k" :style="{ height: b.h + 'px', background: b.bg, animationDelay: b.k * 40 + 'ms' }"></i>
                    </div>
                </div>
                <dl v-if="!voided" class="bf-stats">
                    <div class="bf-stat-tile">
                        <dt class="bf-stat-label">{{ t('play.solved.time') }}</dt>
                        <dd class="bf-stat-value">{{ game.timed ? finalTimeText : t('play.solved.noClock') }}</dd>
                    </div>
                    <div class="bf-stat-tile">
                        <dt class="bf-stat-label">{{ t('play.solved.hintsLabel') }}</dt>
                        <dd class="bf-stat-value">{{ hintsUsedThisRun === 0 ? t('play.solved.none') : hintsUsedThisRun }}</dd>
                    </div>
                    <div class="bf-stat-tile">
                        <dt class="bf-stat-label">{{ t('play.solved.breaks') }}</dt>
                        <dd class="bf-stat-value">{{ game.N }}</dd>
                    </div>
                </dl>
                <p v-else class="bf-help mt-4">{{ t('play.solved.revealedNote') }}</p>

                <p v-if="personalBestNote" class="bf-help mt-2 font-semibold text-good" role="status">{{ t('play.solved.personalBest') }}</p>

                <div v-if="isCampaign && campaignResult" class="bf-xp-card mt-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="font-semibold">{{ t('play.campaign.level', { level: campaignResult.level }) }}</span>
                        <span class="font-semibold">{{ t('play.campaign.points', { points: campaignResult.xpAwarded }) }}</span>
                    </div>
                    <div class="bf-xp-track">
                        <div class="bf-xp-fill" :style="{ width: xpAfterPct + '%' }"></div>
                        <div
                            v-if="xpAfterPct > xpBeforePct"
                            class="bf-xp-fill-new"
                            :style="{ left: xpBeforePct + '%', width: xpAfterPct - xpBeforePct + '%' }"
                        ></div>
                    </div>
                    <span v-if="campaignResult.xpAwarded === 0" class="bf-help">{{ t('play.campaign.noPoints') }}</span>
                    <p v-if="campaignResult.leveledUp" class="bf-levelup m-0">{{ t('play.campaign.levelUp', { level: campaignResult.level }) }}</p>
                    <p v-if="campaignResult.campaignComplete" class="m-0 font-semibold">{{ t('play.campaign.complete') }}</p>
                </div>

                <div class="mt-4 flex flex-col">
                    <span class="bf-help">{{ t('play.share.what') }}</span>
                    <pre class="bf-share-preview">{{ shareText }}</pre>
                </div>

                <!-- Progression: every solve feeds the weekly goal and level. -->
                <!-- A signed-in player's numbers come only from the server (a score
                     response); reopening a solved daily has none, so the card
                     stays hidden rather than showing this device's guest log. -->
                <WeeklyProgress v-if="!voided && (serverProgress || !authenticated)" class="mt-4" :progress="serverProgress" :rules="progressRules" />

                <p v-if="guestDailySolved" class="bf-help mt-3 text-center">
                    {{ t('progress.guestSave') }}
                    <Link href="/register" class="underline">{{ t('progress.guestSaveAction') }}</Link>
                    &middot;
                    <Link href="/login" class="underline">{{ t('progress.guestSignIn') }}</Link>
                </p>

                <div class="bf-solved-actions">
                    <Link v-if="nextAction.href" :href="nextAction.href" class="bf-btn bf-btn-primary bf-btn-stack">
                        <span>{{ nextAction.label }}</span>
                        <small v-if="nextAction.detail">{{ nextAction.detail }}</small>
                    </Link>
                    <button v-else type="button" class="bf-btn bf-btn-primary bf-btn-stack" @click="nextAction.run">
                        <span>{{ nextAction.label }}</span>
                        <small v-if="nextAction.detail">{{ nextAction.detail }}</small>
                    </button>
                    <button type="button" class="bf-btn" @click="copyReport">{{ copyFeedback || t('play.share.button') }}</button>
                    <Link href="/" class="bf-btn bf-btn-soft is-wide">{{ t('play.solved.home') }}</Link>
                </div>
            </section>

            <section v-if="isDaily && leaderboard.length" id="daily-leaderboard" class="bf-leaderboard" aria-labelledby="leaderboard-title">
                <h3 id="leaderboard-title">{{ t('play.leaderboard.title') }}</h3>
                <ol>
                    <li v-for="entry in leaderboard" :key="entry.rank">
                        <span>
                            {{ entry.rank }}. {{ entry.name }}
                            <span v-if="entry.hints_used === 0" class="bf-help ml-1">{{ t('play.leaderboard.noHints') }}</span>
                        </span>
                        <span class="font-semibold">{{ fmtClock(entry.time_ms) }}</span>
                    </li>
                </ol>
            </section>
        </main>
    </div>
</template>
