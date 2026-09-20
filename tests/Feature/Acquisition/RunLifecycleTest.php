<?php

use App\Acquisition\Application\RunCounters;
use App\Acquisition\Application\RunLifecycle;
use App\Acquisition\Domain\Enums\HealthStatus;
use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Enums\RunStatus;
use App\Acquisition\Domain\Models\HealthObservation;
use App\Acquisition\Domain\Models\Source;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21T00:00:00Z'));
    $this->lifecycle = app(RunLifecycle::class);
    $this->source = Source::factory()->create();
});

it('moves a run from PENDING through RUNNING to SUCCEEDED and records metrics', function () {
    $run = $this->lifecycle->start($this->source, RunMode::Monitoring);
    expect($run->status)->toBe(RunStatus::Pending)->and($run->started_at)->toBeNull();

    $this->lifecycle->begin($run);
    expect($run->status)->toBe(RunStatus::Running)->and($run->started_at?->toIso8601ZuluString())->toBe('2026-09-21T00:00:00Z');

    $counters = new RunCounters;
    $counters->increment(RunCounters::FETCHED, 4);
    $counters->increment(RunCounters::NEW, 3);
    $counters->increment(RunCounters::UNCHANGED, 1);

    $this->travel(90)->seconds();
    $this->lifecycle->finish($run, $counters);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->finished_at?->toIso8601ZuluString())->toBe('2026-09-21T00:01:30Z')
        ->and($run->counters['new'])->toBe(3)
        ->and(HealthObservation::query()->where('run_id', $run->id)->pluck('value', 'metric')->map(fn ($v) => (float) $v)->all())
        ->toMatchArray(['documents_new' => 3.0, 'fetch_success_rate' => 1.0, 'run_duration_ms' => 90000.0]);
});

// ADR-0004: a run with failed documents is never SUCCEEDED; drift makes it DEGRADED.
it('derives COMPLETED_WITH_ERRORS and DEGRADED from what happened', function () {
    $withErrors = $this->lifecycle->begin($this->lifecycle->start($this->source, RunMode::Monitoring));
    $counters = new RunCounters;
    $counters->increment(RunCounters::FAILED);
    $this->lifecycle->finish($withErrors, $counters);

    $drifted = $this->lifecycle->begin($this->lifecycle->start($this->source, RunMode::Monitoring));
    $this->lifecycle->finish($drifted, new RunCounters, driftDetected: true);

    expect($withErrors->status)->toBe(RunStatus::CompletedWithErrors)
        ->and($drifted->status)->toBe(RunStatus::Degraded);
});

it('rejects illegal transitions', function () {
    $run = $this->lifecycle->start($this->source, RunMode::Monitoring);

    expect(fn () => $this->lifecycle->finish($run, new RunCounters))->toThrow(LogicException::class);

    $this->lifecycle->begin($run);
    $this->lifecycle->finish($run, new RunCounters);

    expect(fn () => $this->lifecycle->begin($run))->toThrow(LogicException::class)
        ->and(fn () => $this->lifecycle->fail($run, 'too late'))->toThrow(LogicException::class);
});

// ADR-0004 circuit breaker: doubling delay, DEGRADED after three, reset on success.
it('backs off after failures, degrades the source after three, and resets on success', function () {
    $lifecycle = $this->lifecycle;
    $fail = function () use ($lifecycle) {
        $run = $lifecycle->begin($lifecycle->start($this->source, RunMode::Monitoring));

        return $lifecycle->fail($run, new RuntimeException('boom'));
    };

    $first = $fail();
    $this->source->refresh();
    expect($first->status)->toBe(RunStatus::Failed)
        ->and($first->error_message)->toContain('RuntimeException: boom')
        ->and($this->source->consecutive_failures)->toBe(1)
        ->and($this->source->next_run_not_before?->toIso8601ZuluString())->toBe('2026-09-21T01:00:00Z')
        ->and($lifecycle->isBlocked($this->source))->toBeTrue()
        ->and($this->source->health_status)->toBe(HealthStatus::Healthy);

    $fail();
    $this->source->refresh();
    expect($this->source->consecutive_failures)->toBe(2)
        ->and($this->source->next_run_not_before?->toIso8601ZuluString())->toBe('2026-09-21T02:00:00Z');

    $third = $fail();
    $this->source->refresh();
    expect($this->source->consecutive_failures)->toBe(3)
        ->and($this->source->next_run_not_before?->toIso8601ZuluString())->toBe('2026-09-21T04:00:00Z')
        ->and($this->source->health_status)->toBe(HealthStatus::Degraded)
        ->and($this->source->events()->where('event_type', 'SOURCE_DEGRADED')->where('run_id', $third->id)->exists())->toBeTrue();

    $recovered = $lifecycle->begin($lifecycle->start($this->source, RunMode::Monitoring));
    $lifecycle->finish($recovered, new RunCounters);
    $this->source->refresh();

    expect($this->source->consecutive_failures)->toBe(0)
        ->and($this->source->next_run_not_before)->toBeNull()
        ->and($lifecycle->isBlocked($this->source))->toBeFalse()
        // Health recovery needs evidence and is Milestone 5's job; the breaker alone does not heal it.
        ->and($this->source->health_status)->toBe(HealthStatus::Degraded);
});

it('caps the backoff at the configured maximum', function () {
    config(['acquisition.circuit_breaker.max_minutes' => 90]);

    foreach (range(1, 3) as $i) {
        $run = $this->lifecycle->begin($this->lifecycle->start($this->source, RunMode::Monitoring));
        $this->lifecycle->fail($run, 'x');
    }

    expect($this->source->refresh()->next_run_not_before?->toIso8601ZuluString())->toBe('2026-09-21T01:30:00Z');
});
