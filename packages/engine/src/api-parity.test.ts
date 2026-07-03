/**
 * Contract parity — the public surface must match contracts/engine-api.d.ts
 * EXACTLY. A compile error in this file means the surface drifted (ADR-0011:
 * fixing it happens on the engine side, never in contracts/).
 */
import { describe, expect, expectTypeOf, it } from 'vitest';
import type * as contract from '../../../contracts/engine-api';
import * as engine from './index';

// The load-bearing assignment: every contract export must exist here with an
// assignable signature. Do not weaken this line.
const check: typeof import('../../../contracts/engine-api') = engine;

describe('engine-api contract parity', () => {
  it('value surface satisfies the contract (compile-time)', () => {
    expect(check).toBe(engine);
  });

  it('function types match exactly (compile-time)', () => {
    expectTypeOf<typeof engine.shadingToBits>().toEqualTypeOf<typeof contract.shadingToBits>();
    expectTypeOf<typeof engine.bitsToShading>().toEqualTypeOf<typeof contract.bitsToShading>();
    expectTypeOf<typeof engine.validate>().toEqualTypeOf<typeof contract.validate>();
    expectTypeOf<typeof engine.burnTimes>().toEqualTypeOf<typeof contract.burnTimes>();
    expectTypeOf<typeof engine.countSolutions>().toEqualTypeOf<typeof contract.countSolutions>();
    expectTypeOf<typeof engine.deduce>().toEqualTypeOf<typeof contract.deduce>();
    expectTypeOf<typeof engine.generate>().toEqualTypeOf<typeof contract.generate>();
    expectTypeOf<typeof engine.grade>().toEqualTypeOf<typeof contract.grade>();
    expectTypeOf<typeof engine.encodePuzzle>().toEqualTypeOf<typeof contract.encodePuzzle>();
    expectTypeOf<typeof engine.decodePuzzle>().toEqualTypeOf<typeof contract.decodePuzzle>();
  });

  it('exported types match exactly (compile-time)', () => {
    expectTypeOf<engine.Rng>().toEqualTypeOf<contract.Rng>();
    expectTypeOf<engine.Cell>().toEqualTypeOf<contract.Cell>();
    expectTypeOf<engine.Clue>().toEqualTypeOf<contract.Clue>();
    expectTypeOf<engine.BoardSpec>().toEqualTypeOf<contract.BoardSpec>();
    expectTypeOf<engine.Shading>().toEqualTypeOf<contract.Shading>();
    expectTypeOf<engine.BurnVerdictReason>().toEqualTypeOf<contract.BurnVerdictReason>();
    expectTypeOf<engine.BurnResult>().toEqualTypeOf<contract.BurnResult>();
    expectTypeOf<engine.CountOptions>().toEqualTypeOf<contract.CountOptions>();
    expectTypeOf<engine.CountResult>().toEqualTypeOf<contract.CountResult>();
    expectTypeOf<engine.DeductionKind>().toEqualTypeOf<contract.DeductionKind>();
    expectTypeOf<engine.DeductionReason>().toEqualTypeOf<contract.DeductionReason>();
    expectTypeOf<engine.DeductionStep>().toEqualTypeOf<contract.DeductionStep>();
    expectTypeOf<engine.DeductionResult>().toEqualTypeOf<contract.DeductionResult>();
    expectTypeOf<engine.GenerateParams>().toEqualTypeOf<contract.GenerateParams>();
    expectTypeOf<engine.GeneratedPuzzle>().toEqualTypeOf<contract.GeneratedPuzzle>();
    expectTypeOf<engine.Grade>().toEqualTypeOf<contract.Grade>();
  });
});
