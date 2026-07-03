/** Grading v1: the length of the certified deduction chain. */
import { deduce } from './deduce';
import type { BoardSpec, Grade } from './types';

export function grade(board: BoardSpec): Grade {
  const result = deduce(board);
  if (result === null) {
    throw new Error('grade: board is not deduction-solvable');
  }
  return { deductionSteps: result.steps.length };
}
