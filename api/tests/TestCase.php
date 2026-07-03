<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Spectator validates responses against the frozen contract (repo root).
        config()->set('spectator.sources.local.base_path', dirname(base_path()).'/contracts');
        config()->set('spectator.path_prefix', 'api/v1');

        // Simulate the same-origin SPA: Sanctum treats requests carrying this
        // Origin as stateful (cookie session + CSRF), like the real frontend.
        /** @var string $origin */
        $origin = config('app.url');
        $this->withHeader('Origin', $origin);
    }
}
