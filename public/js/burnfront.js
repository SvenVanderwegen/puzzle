/* Burnfront client. Puzzle generation is server-authoritative (see
   BurnfrontController@puzzle) — this file only builds the board and
   validates a player's marking locally, so solving gives instant feedback
   without a round trip. Ported from the reference prototype's fb-engine. */
(function () {
  "use strict";

  function buildAdj(R, C) {
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

  /* BFS from spark over cells where pass(i) is true; -1 = unreachable */
  function bfs(n, adj, spark, pass) {
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

  /* Validate a player's complete marking. breaks: Uint8Array (1 = shaded).
     Doesn't need the solution — recomputes burn times from the marking and
     checks them against the clues, same as the server would. */
  function validate(n, adj, spark, clueIdx, clueVal, N, breaks) {
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

  const COLS = "ABCDEFGHIJ";

  const grid = document.getElementById("grid");
  const veil = document.getElementById("veil");
  const newBtn = document.getElementById("newBtn");
  const undoBtn = document.getElementById("undoBtn");
  const resetBtn = document.getElementById("resetBtn");
  const breakChip = document.getElementById("breakChip");
  const breakCount = document.getElementById("breakCount");
  const clock = document.getElementById("clock");
  const hint = document.getElementById("hint");
  const banner = document.getElementById("banner");
  const finalTime = document.getElementById("finalTime");
  const toast = document.getElementById("toast");
  const segButtons = [...document.querySelectorAll(".seg button")];

  let game = null; /* {n, adj, spark, clueMap, R, C, N} */
  let marks = null; /* Int8Array: 0 none, 1 break, 2 dot */
  let cells = [];
  let undoStack = [];
  let locked = false;
  let startAt = 0,
    clockTimer = null,
    toastTimer = null;
  let genToken = 0;
  let diff = document.body.dataset.defaultDifficulty || "lookout";

  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  function fmt(ms) {
    const s = Math.max(0, Math.floor(ms / 1000));
    return Math.floor(s / 60) + ":" + String(s % 60).padStart(2, "0");
  }
  function startClock() {
    stopClock();
    startAt = Date.now();
    clock.textContent = "0:00";
    clockTimer = setInterval(() => {
      clock.textContent = fmt(Date.now() - startAt);
    }, 1000);
  }
  function stopClock() {
    if (clockTimer) {
      clearInterval(clockTimer);
      clockTimer = null;
    }
  }

  function hideToast() {
    clearTimeout(toastTimer);
    toast.classList.remove("show");
  }
  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("show"), 3400);
  }

  function cellName(i) {
    const C = game.C;
    return COLS[i % C] + (Math.floor(i / C) + 1);
  }
  function cellLabel(i) {
    if (i === game.spark) return cellName(i) + ", the spark";
    if (game.clueMap.has(i)) return cellName(i) + ", clue: burns at minute " + game.clueMap.get(i);
    const m = marks[i];
    return cellName(i) + (m === 1 ? ", firebreak" : m === 2 ? ", marked clear" : ", empty");
  }

  function breaksPlaced() {
    let k = 0;
    for (let i = 0; i < marks.length; i++) if (marks[i] === 1) k++;
    return k;
  }

  function updateChrome() {
    const p = breaksPlaced();
    breakCount.textContent = p + "/" + game.N;
    breakChip.classList.toggle("over", p > game.N);
    undoBtn.disabled = locked || undoStack.length === 0;
    resetBtn.disabled = locked;
  }

  function paintCell(i) {
    const b = cells[i];
    b.classList.toggle("break", marks[i] === 1);
    b.classList.toggle("dot", marks[i] === 2);
    b.setAttribute("aria-label", cellLabel(i));
  }

  function buildGrid() {
    grid.innerHTML = "";
    grid.classList.remove("done");
    grid.style.setProperty("--cols", game.C);
    grid.style.gridTemplateColumns = "repeat(" + game.C + ",1fr)";
    cells = [];
    for (let i = 0; i < game.R * game.C; i++) {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "cell";
      const span = document.createElement("span");
      span.className = "min";
      if (i === game.spark) {
        b.classList.add("spark", "fixed");
        span.textContent = "★";
      } else if (game.clueMap.has(i)) {
        b.classList.add("clue", "fixed");
        span.textContent = game.clueMap.get(i);
      }
      b.appendChild(span);
      b.setAttribute("aria-label", "");
      b.addEventListener("click", () => tap(i, 1));
      b.addEventListener("contextmenu", (ev) => {
        ev.preventDefault();
        tap(i, -1);
      });
      grid.appendChild(b);
      cells.push(b);
    }
    for (let i = 0; i < cells.length; i++) paintCell(i);
  }

  function tap(i, dir) {
    if (locked || !game) return;
    if (i === game.spark || game.clueMap.has(i)) return;
    const prev = marks[i];
    marks[i] = (prev + dir + 3) % 3;
    undoStack.push([[i, prev]]);
    paintCell(i);
    updateChrome();
    maybeFinish(prev !== 1 && marks[i] === 1);
  }

  function undo() {
    if (locked || !undoStack.length) return;
    const entry = undoStack.pop();
    for (const [i, prev] of entry) {
      marks[i] = prev;
      paintCell(i);
    }
    updateChrome();
  }

  function reset() {
    if (locked || !game) return;
    hideToast();
    const entry = [];
    for (let i = 0; i < marks.length; i++)
      if (marks[i] !== 0) {
        entry.push([i, marks[i]]);
        marks[i] = 0;
        paintCell(i);
      }
    if (entry.length) undoStack.push(entry);
    updateChrome();
  }

  function maybeFinish(justCompleted) {
    const p = breaksPlaced();
    if (p !== game.N) return;
    const breaks = new Uint8Array(marks.length);
    for (let i = 0; i < marks.length; i++) breaks[i] = marks[i] === 1 ? 1 : 0;
    const d = validate(game.R * game.C, game.adj, game.spark, game.clueIdx, game.clueVal, game.N, breaks);
    if (d) {
      win(d);
    } else if (justCompleted) {
      showToast("All " + game.N + " breaks are down, but the fire disagrees with the report. Something’s off.");
    }
  }

  function win(times) {
    locked = true;
    hideToast();
    stopClock();
    grid.classList.add("done");
    updateChrome();
    let maxT = 0;
    for (let i = 0; i < times.length; i++) if (times[i] > maxT) maxT = times[i];
    const step = reducedMotion ? 0 : Math.min(140, 1400 / Math.max(1, maxT));
    for (let i = 0; i < cells.length; i++) {
      if (times[i] < 0) continue; /* firebreaks stay dark */
      const b = cells[i],
        t = times[i];
      const warm = maxT ? t / maxT : 0; /* flame core early, deep ember late */
      const mix = (a, c, f) => Math.round(a + (c - a) * f);
      const f = 0.25 + 0.6 * warm; /* 0 -> mostly flame, 1 -> mostly ember */
      b.style.setProperty("--burn-bg", "rgb(" + mix(255, 255, f) + "," + mix(216, 138, f) + "," + mix(107, 61, f) + ")");
      b.style.transitionDelay = t * step + "ms";
      if (!game.clueMap.has(i) && i !== game.spark) b.firstChild.textContent = t;
      b.classList.add("burn");
    }
    const total = maxT * step + 500;
    const token = genToken;
    setTimeout(() => {
      if (token !== genToken) return;
      for (const b of cells) b.style.transitionDelay = "0ms";
      finalTime.textContent = fmt(Date.now() - startAt);
      hint.style.display = "none";
      banner.classList.add("show");
    }, total);
  }

  async function newGame() {
    const token = ++genToken;
    locked = true;
    stopClock();
    hideToast();
    veil.classList.add("show");
    banner.classList.remove("show");
    hint.style.display = "";
    try {
      const resp = await fetch("/puzzle?difficulty=" + encodeURIComponent(diff));
      if (!resp.ok) throw new Error("puzzle request failed");
      const p = await resp.json();
      if (token !== genToken) return; /* superseded by a newer request */
      const n = p.rows * p.cols;
      game = {
        n,
        adj: buildAdj(p.rows, p.cols),
        spark: p.spark,
        R: p.rows,
        C: p.cols,
        N: p.breaks,
        clueMap: new Map(p.clues),
        clueIdx: p.clues.map((cv) => cv[0]),
        clueVal: p.clues.map((cv) => cv[1]),
      };
      marks = new Int8Array(n);
      undoStack = [];
      locked = false;
      buildGrid();
      updateChrome();
      startClock();
    } catch (e) {
      showToast("Couldn't reach the incident desk. Try again.");
    } finally {
      if (token === genToken) veil.classList.remove("show");
    }
  }

  segButtons.forEach((b) =>
    b.addEventListener("click", () => {
      if (b.dataset.diff === diff) return;
      diff = b.dataset.diff;
      segButtons.forEach((x) => x.classList.toggle("on", x === b));
      newGame();
    })
  );
  newBtn.addEventListener("click", newGame);
  undoBtn.addEventListener("click", undo);
  resetBtn.addEventListener("click", reset);

  newGame();
})();
