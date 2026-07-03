<?php

declare(strict_types=1);

namespace App\Domain\Solves;

use InvalidArgumentException;

/**
 * A board spec that fails the burnfront.puzzle/1 `board` shape (contracts/
 * schemas/puzzle.v1.json). Thrown by Board::fromArray; the solve endpoint maps
 * it to a 422 error envelope.
 */
final class InvalidBoardSpec extends InvalidArgumentException {}
