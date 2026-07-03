/**
 * Endless generation worker — the ONLY module in apps/web that imports the
 * engine's generate()/grade() (boundary enforced by boundary.test.ts). All
 * board generation happens off the main thread; the page only ever exchanges
 * protocol messages with this file (via workerFactory.ts).
 *
 * Generation is synchronous inside the worker, so a cancelled request cannot
 * be interrupted — it simply finishes and its response is dropped client-side
 * by token (protocol.ts). deduction_steps comes from engine grade() on the
 * generated board (RATING.md §4: the client-graded chain length).
 */
import { generate, grade } from '@burnfront/engine';
import { isGenerateRequest, type WorkerResponse } from './protocol';
import { seededRng } from './rng';

export function handleGenerateMessage(data: unknown, post: (r: WorkerResponse) => void): void {
  if (!isGenerateRequest(data)) return;
  try {
    const puzzle = generate(
      { rows: data.rows, cols: data.cols, breaks: data.breaks, minClues: data.minClues },
      seededRng(data.seed),
    );
    post({
      kind: 'result',
      token: data.token,
      board: puzzle.board,
      deductionSteps: grade(puzzle.board).deductionSteps,
    });
  } catch (error) {
    post({
      kind: 'failure',
      token: data.token,
      message: error instanceof Error ? error.message : 'generate failed',
    });
  }
}

/** Minimal worker-global surface (DOM lib lacks DedicatedWorkerGlobalScope). */
interface WorkerScope {
  addEventListener(type: 'message', listener: (event: { data: unknown }) => void): void;
  postMessage(message: unknown): void;
}

const scope = self as unknown as WorkerScope;
scope.addEventListener('message', (event) => {
  handleGenerateMessage(event.data, (response) => {
    scope.postMessage(response);
  });
});
