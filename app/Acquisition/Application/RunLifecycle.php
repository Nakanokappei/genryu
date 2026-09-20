<?php

namespace App\Acquisition\Application;

use App\Acquisition\Domain\Enums\HealthStatus;
use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Enums\RunStatus;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use App\Acquisition\Tools\Storage\StorageTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The acquisition run state machine (plan §15 M3, ADR-0004):
 *
 *   PENDING -> RUNNING -> SUCCEEDED | COMPLETED_WITH_ERRORS | DEGRADED | FAILED
 *
 * finish() derives the terminal status from the counters so a run with
 * failed documents can never be reported as SUCCEEDED. fail() drives the
 * per-source circuit breaker. Every transition is logged as structured JSON.
 */
final class RunLifecycle
{
    public function __construct(private StorageTool $storage) {}

    /**
     * Create a PENDING run. Nothing has happened yet; begin() marks the
     * moment work actually starts (which may be later, on a queue worker).
     *
     * @param  array<string, mixed>|null  $budget
     * @param  array<string, mixed>|null  $agentMetadata
     */
    public function start(Source $source, RunMode $mode, ?SourceProfile $profile = null, ?array $budget = null, ?array $agentMetadata = null): AcquisitionRun
    {
        $run = AcquisitionRun::query()->create([
            'mode' => $mode,
            'source_id' => $source->id,
            'profile_id' => $profile?->id,
            'status' => RunStatus::Pending,
            'budget' => $budget,
            'agent_metadata' => $agentMetadata,
            'counters' => (new RunCounters)->toArray(),
        ]);

        $this->log('info', 'acquisition.run.created', $run);

        return $run;
    }

    /**
     * PENDING -> RUNNING.
     */
    public function begin(AcquisitionRun $run): AcquisitionRun
    {
        $this->assertTransition($run, RunStatus::Running);

        $run->update(['status' => RunStatus::Running, 'started_at' => CarbonImmutable::now('UTC')]);
        $this->log('info', 'acquisition.run.started', $run);

        return $run;
    }

    /**
     * RUNNING -> terminal status derived from what happened. Resets the
     * circuit breaker and records the run's health metrics.
     */
    public function finish(AcquisitionRun $run, RunCounters $counters, bool $driftDetected = false): AcquisitionRun
    {
        $status = match (true) {
            $driftDetected => RunStatus::Degraded,
            $counters->get(RunCounters::FAILED) > 0 => RunStatus::CompletedWithErrors,
            default => RunStatus::Succeeded,
        };

        $this->assertTransition($run, $status);
        $finishedAt = CarbonImmutable::now('UTC');

        DB::transaction(function () use ($run, $counters, $status, $finishedAt): void {
            $run->update(['status' => $status, 'finished_at' => $finishedAt, 'counters' => $counters->toArray()]);

            // Any completed run proves the source is reachable again.
            $run->source()->update(['consecutive_failures' => 0, 'next_run_not_before' => null]);

            $this->recordMetrics($run, $counters, $finishedAt);
        });

        $this->log('info', 'acquisition.run.finished', $run->refresh());

        return $run;
    }

    /**
     * RUNNING (or PENDING) -> FAILED. Advances the circuit breaker: the next
     * run waits base * 2^(n-1) minutes, capped, and the source is marked
     * DEGRADED after enough consecutive failures.
     */
    public function fail(AcquisitionRun $run, Throwable|string $reason, ?RunCounters $counters = null): AcquisitionRun
    {
        $this->assertTransition($run, RunStatus::Failed);
        $message = $reason instanceof Throwable ? $reason::class.': '.$reason->getMessage() : $reason;
        $now = CarbonImmutable::now('UTC');

        DB::transaction(function () use ($run, $counters, $message, $now): void {
            $run->update([
                'status' => RunStatus::Failed,
                'finished_at' => $now,
                'error_message' => mb_substr($message, 0, 2000),
                'counters' => ($counters ?? new RunCounters)->toArray(),
            ]);

            /** @var Source $source */
            $source = Source::query()->whereKey($run->source_id)->lockForUpdate()->firstOrFail();
            $failures = $source->consecutive_failures + 1;
            /** @var array{base_minutes: int, max_minutes: int, degrade_after: int} $breaker */
            $breaker = config('acquisition.circuit_breaker');
            $delay = min($breaker['max_minutes'], $breaker['base_minutes'] * (2 ** ($failures - 1)));

            $source->fill(['consecutive_failures' => $failures, 'next_run_not_before' => $now->addMinutes($delay)]);

            if ($failures >= $breaker['degrade_after'] && $source->health_status === HealthStatus::Healthy) {
                $source->health_status = HealthStatus::Degraded;
                $source->events()->create([
                    'run_id' => $run->id,
                    'event_type' => 'SOURCE_DEGRADED',
                    'severity' => 'warning',
                    'evidence' => ['consecutive_failures' => $failures, 'last_error' => mb_substr($message, 0, 500), 'next_run_not_before' => $now->addMinutes($delay)->toIso8601ZuluString()],
                ]);
            }

            $source->save();
        });

        $this->log('error', 'acquisition.run.failed', $run->refresh(), ['reason' => mb_substr($message, 0, 500)]);

        return $run;
    }

    /**
     * Whether the breaker currently blocks a new run for the source.
     */
    public function isBlocked(Source $source): bool
    {
        return $source->next_run_not_before !== null && $source->next_run_not_before->isFuture();
    }

    private function recordMetrics(AcquisitionRun $run, RunCounters $counters, CarbonImmutable $at): void
    {
        $source = $run->source;
        $fetched = $counters->get(RunCounters::FETCHED);
        $metrics = [
            'run_duration_ms' => $run->started_at !== null ? (float) $run->started_at->diffInMilliseconds($at) : 0.0,
            'documents_new' => (float) $counters->get(RunCounters::NEW),
            'documents_revised' => (float) $counters->get(RunCounters::REVISED),
            'documents_failed' => (float) $counters->get(RunCounters::FAILED),
            'documents_quality_failed' => (float) $counters->get(RunCounters::QUALITY_FAILED),
        ];

        if ($fetched > 0) {
            $metrics['fetch_success_rate'] = ($fetched - $counters->get(RunCounters::FAILED)) / $fetched;
        }

        // Baselines and status judgements arrive with Health in Milestone 5.
        foreach ($metrics as $metric => $value) {
            $this->storage->recordHealthObservation($source, $run, $metric, $value, null, null, $at);
        }
    }

    private function assertTransition(AcquisitionRun $run, RunStatus $to): void
    {
        $allowed = match ($run->status) {
            RunStatus::Pending => [RunStatus::Running, RunStatus::Failed],
            RunStatus::Running => [RunStatus::Succeeded, RunStatus::CompletedWithErrors, RunStatus::Degraded, RunStatus::Failed],
            default => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new LogicException("Run {$run->id} cannot move from {$run->status->value} to {$to->value}.");
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $level, string $event, AcquisitionRun $run, array $extra = []): void
    {
        Log::channel('acquisition')->log($level, $event, [
            'run_id' => $run->id,
            'source_id' => $run->source_id,
            'mode' => $run->mode->value,
            'status' => $run->status->value,
            'profile_id' => $run->profile_id,
            'counters' => $run->counters,
            ...$extra,
        ]);
    }
}
