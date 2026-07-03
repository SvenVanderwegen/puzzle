/**
 * @burnfront/engine — Firebreak rules engine. Pure TypeScript, zero runtime
 * dependencies. Public surface is frozen by contracts/engine-api.d.ts
 * (ADR-0011); behavioral truth lives in contracts/vectors/.
 */
export { bitsToShading, shadingToBits } from './bits';
export { decodePuzzle, encodePuzzle } from './codec';
export { countSolutions } from './count';
export { deduce } from './deduce';
export { generate } from './generate';
export { grade } from './grade';
export { burnTimes, validate } from './validate';
export type {
  BoardSpec,
  BurnResult,
  BurnVerdictReason,
  Cell,
  Clue,
  CountOptions,
  CountResult,
  DeductionKind,
  DeductionReason,
  DeductionResult,
  DeductionStep,
  GeneratedPuzzle,
  GenerateParams,
  Grade,
  Rng,
  Shading,
} from './types';
