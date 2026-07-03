<?php

declare(strict_types=1);

use App\Domain\Solves\Board;
use App\Domain\Solves\BurnValidator;

// Gate 4 (playbook §5): the PHP validator must agree with the full cross-language
// vector file. contracts/vectors/burn.v1.jsonl is produced ONLY by the Python
// reference; if this test fails, the PHP code is wrong — never the vectors.

test('the PHP BurnValidator agrees with every burn vector', function (): void {
    $path = dirname(base_path()).'/contracts/vectors/burn.v1.jsonl';
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($lines)->not->toBeFalse();

    /** @var list<string> $lines */
    expect(count($lines))->toBe(509);

    $validator = new BurnValidator;
    $checked = 0;

    foreach ($lines as $line) {
        /** @var array{id: string, rows: int, cols: int, breaks: int, spark: array{int, int}, clues: list<array{r: int, c: int, m: int}>, shading: string, times: list<int>, valid: bool, reason: string} $vector */
        $vector = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $board = Board::fromArray([
            'rows' => $vector['rows'],
            'cols' => $vector['cols'],
            'spark' => $vector['spark'],
            'breaks' => $vector['breaks'],
            'clues' => $vector['clues'],
        ]);

        $verdict = $validator->verdict($board, $vector['shading']);

        expect($verdict->valid)->toBe($vector['valid'], "{$vector['id']}: valid mismatch");
        expect($verdict->reason->value)->toBe($vector['reason'], "{$vector['id']}: reason mismatch");
        expect($verdict->times)->toBe($vector['times'], "{$vector['id']}: times mismatch");

        $checked++;
    }

    expect($checked)->toBe(509);
});
