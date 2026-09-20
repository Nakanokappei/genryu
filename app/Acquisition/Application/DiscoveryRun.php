<?php

namespace App\Acquisition\Application;

use App\Acquisition\Domain\Models\AcquisitionRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queue job for one DISCOVERY run. Idempotent and never retried by the
 * queue (ADR-0004); the queue timeout outlives the worker timeout so the
 * child process is never orphaned.
 */
final class DiscoveryRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = (int) config('acquisition.worker.timeout_seconds') + 60;
    }

    public function handle(DiscoveryRunner $runner): void
    {
        $run = AcquisitionRun::query()->findOrFail($this->runId);

        if ($run->status->isFinished()) {
            Log::channel('acquisition')->info('acquisition.run.skipped_finished', ['run_id' => $run->id, 'status' => $run->status->value]);

            return;
        }

        $runner->execute($run);
    }
}
