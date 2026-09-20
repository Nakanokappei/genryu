<?php

namespace App\Acquisition\Reprocessing;

use App\Acquisition\Application\RunCounters;
use App\Acquisition\Application\RunLifecycle;
use App\Acquisition\Domain\Enums\RunStatus;
use App\Acquisition\Domain\Models\AcquisitionRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue job that executes one REPROCESS run. Idempotent: a run that has
 * already finished is left alone, so a redelivered job does nothing. Never
 * retried by the queue (ADR-0004); a failed run is re-run as a new run.
 */
final class ReprocessRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param  array<string, mixed>  $request  ReprocessRequest::toArray()
     */
    public function __construct(
        public readonly int $runId,
        public readonly array $request,
    ) {
        $this->timeout = (int) config('acquisition.run_timeout_seconds');
    }

    public function handle(RunLifecycle $lifecycle, ReprocessService $reprocess): void
    {
        $run = AcquisitionRun::query()->findOrFail($this->runId);

        if ($run->status->isFinished()) {
            Log::channel('acquisition')->info('acquisition.run.skipped_finished', ['run_id' => $run->id, 'status' => $run->status->value]);

            return;
        }

        if ($run->status === RunStatus::Pending) {
            $lifecycle->begin($run);
        }

        $counters = new RunCounters;

        try {
            $reprocess->execute(ReprocessRequest::fromArray($this->request), $run, $counters);
            $lifecycle->finish($run, $counters);
        } catch (Throwable $exception) {
            $lifecycle->fail($run, $exception, $counters);

            throw $exception;
        }
    }
}
