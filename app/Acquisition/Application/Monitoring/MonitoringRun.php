<?php

namespace App\Acquisition\Application\Monitoring;

use App\Acquisition\Domain\Models\AcquisitionRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queue job for one MONITORING run: idempotent, never retried (ADR-0004).
 */
final class MonitoringRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = (int) config('acquisition.run_timeout_seconds');
    }

    public function handle(MonitoringRunner $runner): void
    {
        $run = AcquisitionRun::query()->findOrFail($this->runId);

        if ($run->status->isFinished()) {
            Log::channel('acquisition')->info('acquisition.run.skipped_finished', ['run_id' => $run->id, 'status' => $run->status->value]);

            return;
        }

        $runner->execute($run);
    }
}
