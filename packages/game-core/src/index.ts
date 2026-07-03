/**
 * @burnfront/game-core — framework-agnostic play-state machines (WS-03).
 * No DOM, no React, no network; the only import is @burnfront/engine. All
 * environment effects (clock, storage, gzip, sha256, RNG) are injected.
 */
export { CoachState } from './coach';
export type { CoachHint, CoachHost, CoachStage } from './coach';
export { MarkHistory } from './history';
export { MarksBoard, marksFromString, marksToString } from './marks';
export {
  clearSnapshot,
  isSessionSnapshot,
  loadSnapshot,
  MemoryStorage,
  saveSnapshot,
} from './persistence';
export { revealSequence } from './replay';
export type { RevealFrame, RevealSequence } from './replay';
export { PlaySession } from './session';
export type { SessionInit, SessionSnapshot } from './session';
export {
  asciiToBytes,
  assembleSolveRecord,
  bytesToBase64,
  encodeReplayLog,
  uuidV7,
} from './solve-record';
export type { SolveRecord, SolveRecordDeps, SolveRecordSource } from './solve-record';
export { SessionTimer } from './timer';
export type { TimerState } from './timer';
export { MARK_CODES, MARK_FROM_CODE, toWireBoard } from './types';
export type {
  Clock,
  Compressor,
  Hasher,
  HintCounts,
  KeyValueStorage,
  Mark,
  MarkChange,
  MarkCode,
  ReplayEvent,
  SolveMode,
  SolveSubmission,
  WireBoard,
} from './types';
