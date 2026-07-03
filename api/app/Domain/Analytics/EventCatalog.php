<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * The event catalog (product §8, ADR-0008, WS-19 brief): every analytics event
 * name and its exact props schema. Payload validation happens here — cheaply,
 * before any DB write — so a schema-invalid batch never costs more than a few
 * array lookups.
 *
 * Props are a closed record per name: every declared key is required, no
 * undeclared key is accepted, and values are typed scalars. That keeps the
 * table free of accidental PII (no free-form payloads) and keeps the digest
 * math trustworthy.
 *
 * Retention reserves a namespace inside the same table (no new tables allowed
 * post-freeze): monthly rollup rows use name `_rollup.<name>` and
 * anon_id `_system`. The API enum below can never produce either value, so raw
 * and rollup rows coexist without collision (docs/gdpr.md, retention table).
 */
final class EventCatalog
{
    /** Rollup rows: `_rollup.<original name>` (retention, docs/gdpr.md). */
    public const string ROLLUP_PREFIX = '_rollup.';

    /** Rollup rows carry this anon_id; excluded from all per-user semantics. */
    public const string SYSTEM_ANON_ID = '_system';

    /** Contract cap: AnalyticsEvent.props maxProperties (openapi.yaml). */
    public const int MAX_PROPS = 12;

    /**
     * name => prop => spec. Types: int (with min/max), bool, fraction
     * (number in [0,1]), string (with max length).
     *
     * @var array<string, array<string, array{type: 'int'|'bool'|'fraction'|'string', min?: int, max?: int}>>
     */
    private const array SCHEMAS = [
        'first_seen' => [],
        'tutorial_step' => [
            'n' => ['type' => 'int', 'min' => 0, 'max' => 100],
        ],
        'solve_start' => [],
        'solve_complete' => [
            'puzzle_id' => ['type' => 'string', 'max' => 64],
            'ms' => ['type' => 'int', 'min' => 0, 'max' => 604_800_000],
            'hint_stages' => ['type' => 'int', 'min' => 0, 'max' => 3],
            'undo_count' => ['type' => 'int', 'min' => 0, 'max' => 100_000],
            'wrong_checks' => ['type' => 'int', 'min' => 0, 'max' => 100_000],
            'first' => ['type' => 'bool'],
        ],
        'board_abandoned' => [
            'ms' => ['type' => 'int', 'min' => 0, 'max' => 604_800_000],
            'marks_placed' => ['type' => 'int', 'min' => 0, 'max' => 100_000],
            'last_action_ms' => ['type' => 'int', 'min' => 0, 'max' => 604_800_000],
        ],
        'hint_used' => [
            'stage' => ['type' => 'int', 'min' => 1, 'max' => 3],
        ],
        'replay_watched' => [
            'fraction' => ['type' => 'fraction'],
        ],
        'share_clicked' => [],
        'account_created' => [
            'from_nudge' => ['type' => 'bool'],
        ],
    ];

    /**
     * The contract enum (openapi.yaml AnalyticsEvent.name).
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::SCHEMAS);
    }

    /**
     * Validates the props record for a known event name. Returns a short
     * error string (folded into the 422 envelope by the controller) or null
     * when valid. Never touches the database.
     */
    public static function validateProps(string $name, mixed $props): ?string
    {
        $schema = self::SCHEMAS[$name] ?? null;

        if ($schema === null) {
            return sprintf('events.%s: unknown event name.', $name);
        }

        if ($props === null) {
            $props = [];
        }

        if (! is_array($props)) {
            return sprintf('%s: props must be an object.', $name);
        }

        if (count($props) > self::MAX_PROPS) {
            return sprintf('%s: props exceeds %d keys.', $name, self::MAX_PROPS);
        }

        foreach (array_keys($props) as $key) {
            if (! array_key_exists((string) $key, $schema)) {
                return sprintf('%s: unknown prop "%s".', $name, (string) $key);
            }
        }

        foreach ($schema as $key => $spec) {
            if (! array_key_exists($key, $props)) {
                return sprintf('%s: missing prop "%s".', $name, $key);
            }

            $error = self::validateValue($props[$key], $spec);

            if ($error !== null) {
                return sprintf('%s: prop "%s" %s', $name, $key, $error);
            }
        }

        return null;
    }

    /**
     * @param  array{type: 'int'|'bool'|'fraction'|'string', min?: int, max?: int}  $spec
     */
    private static function validateValue(mixed $value, array $spec): ?string
    {
        switch ($spec['type']) {
            case 'int':
                if (! is_int($value)) {
                    return 'must be an integer.';
                }

                if ($value < ($spec['min'] ?? 0) || $value > ($spec['max'] ?? PHP_INT_MAX)) {
                    return 'is out of range.';
                }

                return null;

            case 'bool':
                return is_bool($value) ? null : 'must be a boolean.';

            case 'fraction':
                if (! is_int($value) && ! is_float($value)) {
                    return 'must be a number.';
                }

                $number = (float) $value;

                if (is_nan($number) || $number < 0.0 || $number > 1.0) {
                    return 'must be between 0 and 1.';
                }

                return null;

            case 'string':
                if (! is_string($value) || $value === '') {
                    return 'must be a non-empty string.';
                }

                if (mb_strlen($value) > ($spec['max'] ?? 128)) {
                    return 'is too long.';
                }

                return null;
        }
    }
}
