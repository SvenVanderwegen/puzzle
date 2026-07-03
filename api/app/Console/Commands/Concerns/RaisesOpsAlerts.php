<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Domain\Ops\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Shared alert convention for the ops:* checks (see OpsAlert for the three
 * sinks every raise reaches). Commands using this trait return the value of
 * raiseOpsAlert() so the scheduled run is marked failed.
 */
trait RaisesOpsAlerts
{
    /**
     * @param  array<string, mixed>  $context
     */
    protected function raiseOpsAlert(string $message, array $context = []): int
    {
        Log::channel('ops')->critical($message, $context);
        report(new OpsAlert($message));
        $this->components->error($message);

        return Command::FAILURE;
    }
}
