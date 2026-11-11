// Types for the website's rules engine (resources/js/lib/burnfront-engine.js),
// which the app imports unchanged.
declare module '*/resources/js/lib/burnfront-engine.js' {
  export const COLS: string;
  export function buildAdj(R: number, C: number): number[][];
  export function bfs(n: number, adj: number[][], spark: number, pass: (i: number) => boolean): Int32Array;
  export function validate(
    n: number,
    adj: number[][],
    spark: number,
    clueIdx: ArrayLike<number>,
    clueVal: ArrayLike<number>,
    N: number,
    breaks: ArrayLike<number>,
  ): Int32Array | false;
  export function cellName(i: number, C: number): string;
  export function fmtClock(ms: number): string;
}
