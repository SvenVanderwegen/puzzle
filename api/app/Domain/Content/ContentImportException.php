<?php

declare(strict_types=1);

namespace App\Domain\Content;

use RuntimeException;

/**
 * Refused content: bad signature, hash mismatch, malformed manifest or puzzle
 * doc, or an immutability violation. The console commands map this to a
 * non-zero exit code; nothing is written unless stated otherwise.
 */
final class ContentImportException extends RuntimeException {}
