/**
 * Grid primitives: index math, adjacency, BFS burn wavefront.
 * Ported from reference/index.html (fb-engine) / reference/firebreak.py.
 */
import type { Cell } from './types';

/**
 * Unchecked index read for hot loops; the caller guarantees `i` is in range.
 * (Exists only to keep `noUncheckedIndexedAccess` honest in one place.)
 */
export function at(arr: ArrayLike<number>, i: number): number {
  return arr[i] as number;
}

export function toCell(index: number, cols: number): Cell {
  return { r: Math.floor(index / cols), c: index % cols };
}

export function cellIndex(cell: Cell, cols: number): number {
  return cell.r * cols + cell.c;
}

/** Neighbor lists, order up/down/left/right (parity with the reference). */
export function buildAdjacency(rows: number, cols: number): readonly (readonly number[])[] {
  const adj: number[][] = [];
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const a: number[] = [];
      if (r > 0) a.push((r - 1) * cols + c);
      if (r < rows - 1) a.push((r + 1) * cols + c);
      if (c > 0) a.push(r * cols + c - 1);
      if (c < cols - 1) a.push(r * cols + c + 1);
      adj.push(a);
    }
  }
  return adj;
}

/** Reusable BFS buffers for hot solver loops (one BFS in flight per scratch). */
export interface BfsScratch {
  readonly dist: Int32Array;
  readonly queue: Int32Array;
}

export function makeBfsScratch(n: number): BfsScratch {
  return { dist: new Int32Array(n), queue: new Int32Array(n) };
}

/**
 * BFS minutes from the spark over cells where `passable(i)`; -1 = unreached
 * (including the spark itself when it is not passable — the fire never starts).
 * When `scratch` is given its `dist` buffer is returned; the result is only
 * valid until the next bfs call with the same scratch.
 */
export function bfs(
  n: number,
  adj: readonly (readonly number[])[],
  spark: number,
  passable: (i: number) => boolean,
  scratch?: BfsScratch,
): Int32Array {
  const dist = scratch === undefined ? new Int32Array(n) : scratch.dist;
  dist.fill(-1);
  if (!passable(spark)) return dist;
  dist[spark] = 0;
  const queue = scratch === undefined ? new Int32Array(n) : scratch.queue;
  queue[0] = spark;
  let head = 0;
  let tail = 1;
  while (head < tail) {
    const x = at(queue, head);
    head += 1;
    const ax = adj[x] ?? [];
    const dx = at(dist, x);
    for (const y of ax) {
      if (at(dist, y) < 0 && passable(y)) {
        dist[y] = dx + 1;
        queue[tail] = y;
        tail += 1;
      }
    }
  }
  return dist;
}
