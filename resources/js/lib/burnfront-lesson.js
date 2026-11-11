/* The first-time lesson: a fixed 4x4 board walked through in three steps.
   Ported from the approved design (canvas page "Eerste keer", artboard
   Lesson.dc.html). Pure functions only, so the page stays a thin view and the
   logic can be checked without a browser.

   Phases:
     0  meet the fire       (button starts the burn replay)
     1  one square a minute (burn replay shown)
     2  now stop it         (tap the dashed demo cell)
     3  it goes around      (burn replay with the demo firebreak)
     4  your first puzzle   (2 firebreaks, clues shown, fire replays per tap)
     5  contained           (solved) */

export const LESSON = Object.freeze({
    R: 4,
    C: 4,
    spark: 12,
    clues: Object.freeze({ 4: 2, 14: 6 }),
    N: 2,
    demo: 8,
    maxMinute: 8,
});

const n = LESSON.R * LESSON.C;

export function emptyMarks() {
    return new Array(n).fill(0);
}

/** Burn minutes from the spark; -1 for a firebreak or a cut-off square. */
export function burn(marks) {
    const { R, C, spark } = LESSON;
    const dist = new Array(n).fill(-1);
    const queue = [spark];
    dist[spark] = 0;
    for (let head = 0; head < queue.length; head++) {
        const x = queue[head];
        const r = Math.floor(x / C);
        const c = x % C;
        const next = [];
        if (r > 0) next.push(x - C);
        if (r < R - 1) next.push(x + C);
        if (c > 0) next.push(x - 1);
        if (c < C - 1) next.push(x + 1);
        for (const y of next) {
            if (dist[y] < 0 && marks[y] !== 1) {
                dist[y] = dist[x] + 1;
                queue.push(y);
            }
        }
    }
    return dist;
}

export function countBreaks(marks) {
    return marks.reduce((k, m) => k + (m === 1 ? 1 : 0), 0);
}

export function isSolved(marks) {
    if (countBreaks(marks) !== LESSON.N) return false;
    const dist = burn(marks);
    for (let i = 0; i < n; i++) if (marks[i] !== 1 && dist[i] < 0) return false;
    for (const k in LESSON.clues) if (dist[k] !== LESSON.clues[k]) return false;
    return true;
}

export function isFixed(i) {
    return i === LESSON.spark || LESSON.clues[i] != null;
}

/** Square name like "A1": column letter, row number from the top. */
export function squareName(i) {
    return 'ABCD'[i % LESSON.C] + (Math.floor(i / LESSON.C) + 1);
}

/**
 * What a tap on square i does in the given state. Returns the next state, or
 * null when the tap does nothing. The supply is real: with both firebreaks
 * down, a third tap places nothing and sets `full` so the page can explain.
 */
export function tapResult(state, i) {
    if (state.phase === 2) {
        if (i !== LESSON.demo) return null;
        const marks = emptyMarks();
        marks[i] = 1;
        return { phase: 3, marks, replay: true, full: false };
    }
    if (state.phase !== 4 || isFixed(i)) return null;
    const marks = state.marks.slice();
    if (marks[i] !== 1 && countBreaks(marks) >= LESSON.N) {
        return { ...state, full: true, replay: false };
    }
    marks[i] = marks[i] === 1 ? 0 : 1;
    return { phase: isSolved(marks) ? 5 : 4, marks, replay: true, full: false };
}

/** The state after the step's main button. */
export function advance(state) {
    if (state.phase === 0) return { phase: 1, marks: emptyMarks(), replay: true, full: false };
    if (state.phase === 1) return { phase: 2, marks: emptyMarks(), replay: false, full: false };
    if (state.phase === 3) return { phase: 4, marks: emptyMarks(), replay: false, full: false };
    return state;
}

export function initialState() {
    return { phase: 0, marks: emptyMarks(), replay: false, full: false };
}

/** Which of the three steps a phase belongs to (1-based). */
export function stepOf(phase) {
    if (phase <= 1) return 1;
    if (phase <= 3) return 2;
    return 3;
}

/**
 * The message for phase 4, as a locale key plus params. The 6 is checked
 * before the 2 because it is the clue the player is told to work on.
 * A firebreak only ever delays the fire, so "too late" means a firebreak has
 * to come off, while "too early" means one is still missing.
 */
export function puzzleMessage(state, played) {
    const { marks } = state;
    if (state.full) return { key: 'lesson.phase4.full' };
    if (!played) return { key: 'lesson.phase4.intro' };
    const dist = burn(marks);
    for (let i = 0; i < n; i++) {
        if (marks[i] !== 1 && dist[i] < 0) return { key: 'lesson.phase4.cutOff' };
    }
    for (const k of [14, 4]) {
        const clue = LESSON.clues[k];
        if (dist[k] < clue) return { key: 'lesson.phase4.tooEarly', params: { clue, minute: dist[k] } };
        if (dist[k] > clue) return { key: 'lesson.phase4.tooLate', params: { clue, minute: dist[k] } };
    }
    return { key: 'lesson.phase4.almost' };
}
