<?php

namespace App\Acquisition\Health;

use App\Acquisition\Application\DiscoveryRun;
use App\Acquisition\Application\DiscoveryRunner;
use App\Acquisition\Application\Monitoring\EntrypointReading;
use App\Acquisition\Application\RunCounters;
use App\Acquisition\Domain\Enums\HealthStatus;
use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Enums\RunStatus;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\FetchObservation;
use App\Acquisition\Domain\Models\HealthObservation;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use App\Acquisition\Tools\Storage\StorageTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Silent-failure detection and the health state machine (plan §12, §13).
 * Turns a monitoring run's readings and counters into metrics with
 * baselines, drift evidence, health transitions with evidence, and (once,
 * on entering PARSER_DRIFT) a queued Discovery run that can only produce a
 * candidate. Never activates a profile.
 */
final class HealthEvaluator
{
    public function __construct(
        private StorageTool $storage,
        private DiscoveryRunner $discovery,
    ) {}

    /**
     * @param  list<EntrypointReading>  $readings
     * @param  list<string>  $warnings  pattern/profile warnings collected during the run
     */
    public function evaluate(AcquisitionRun $run, Source $source, SourceProfile $profile, array $readings, RunCounters $counters, array $warnings = []): HealthVerdict
    {
        $now = CarbonImmutable::now('UTC');
        $evidence = [];
        /** @var array{baseline_runs: int, quality_collapse_ratio: float, min_documents_for_ratio: int} $config */
        $config = config('acquisition.monitoring');
        $minimumRatio = (float) ($profile->profile_json['quality_expectations']['minimum_item_ratio_to_baseline'] ?? 0.5);

        // Entrypoints: reachability and entry counts against each one's baseline.
        $reachable = array_filter($readings, static fn (EntrypointReading $reading): bool => $reading->reachable());

        if ($readings !== [] && $reachable === []) {
            $evidence[] = ['kind' => 'entrypoints_unreachable', 'severity' => 'critical', 'detail' => ['entrypoints' => array_map(static fn (EntrypointReading $r): array => $r->toArray(), $readings)]];
        }

        foreach ($readings as $reading) {
            if ($reading->contentTypeChanged) {
                $evidence[] = ['kind' => 'content_type_changed', 'severity' => 'warning', 'detail' => $reading->toArray()];
            }

            if ($reading->entryCount === null) {
                continue;
            }

            $baseline = $this->baseline($source, 'entrypoint_entry_count', $reading->url, $run, $config['baseline_runs']);
            $status = 'HEALTHY';

            if ($baseline !== null && $baseline > 0 && $reading->entryCount < $baseline * $minimumRatio) {
                $status = $reading->entryCount === 0 ? 'CRITICAL' : 'DEGRADED';
                $evidence[] = ['kind' => 'entry_count_collapse', 'severity' => $reading->entryCount === 0 ? 'critical' : 'warning', 'detail' => ['url' => $reading->url, 'observed' => $reading->entryCount, 'baseline' => $baseline, 'minimum_ratio' => $minimumRatio]];
            }

            $this->storage->recordHealthObservation($source, $run, 'entrypoint_entry_count', (float) $reading->entryCount, $baseline, $status, $now, $reading->url);
        }

        // Documents: quality verdicts of everything normalized this run.
        $assessed = $counters->get(RunCounters::NEW) + $counters->get(RunCounters::REVISED);

        if ($assessed >= $config['min_documents_for_ratio']) {
            $failRatio = $counters->get(RunCounters::QUALITY_FAILED) / $assessed;
            $this->storage->recordHealthObservation($source, $run, 'quality_pass_rate', 1 - $failRatio, null, $failRatio >= $config['quality_collapse_ratio'] ? 'DEGRADED' : 'HEALTHY', $now);

            if ($failRatio >= $config['quality_collapse_ratio']) {
                $evidence[] = ['kind' => 'quality_collapse', 'severity' => $failRatio >= 1.0 ? 'critical' : 'warning', 'detail' => ['assessed' => $assessed, 'quality_failed' => $counters->get(RunCounters::QUALITY_FAILED), 'fail_ratio' => round($failRatio, 3), 'sample_failures' => $this->sampleFailures($run)]];
            }
        }

        foreach ($warnings as $warning) {
            $evidence[] = ['kind' => 'profile_warning', 'severity' => 'warning', 'detail' => ['message' => $warning]];
        }

        return $this->transition($run, $source, $profile, $evidence, $now);
    }

    /**
     * Apply the state machine (plan §13) and record the transition.
     *
     * @param  list<array{kind: string, severity: string, detail: array<string, mixed>}>  $evidence
     */
    private function transition(AcquisitionRun $run, Source $source, SourceProfile $profile, array $evidence, CarbonImmutable $now): HealthVerdict
    {
        $from = $source->health_status;
        $drift = $evidence !== [];
        $to = $from;
        $queued = false;

        if ($from === HealthStatus::Disabled) {
            return new HealthVerdict($drift, $evidence, $from, $from);
        }

        if ($drift) {
            $critical = array_filter($evidence, static fn (array $item): bool => $item['severity'] === 'critical') !== [];
            $consecutive = $this->previousRunStatus($run) === RunStatus::Degraded;

            // One critical fact, two independent facts, or a repeat is drift; a single weak signal only degrades.
            $to = ($critical || count($evidence) >= 2 || $consecutive) ? HealthStatus::ParserDrift : HealthStatus::Degraded;

            if ($from === HealthStatus::ParserDrift) {
                $to = HealthStatus::ParserDrift;
            }

            $source->events()->create([
                'run_id' => $run->id,
                'event_type' => $to === HealthStatus::ParserDrift ? 'PARSER_DRIFT' : 'SOURCE_DEGRADED',
                'severity' => $to === HealthStatus::ParserDrift ? 'error' : 'warning',
                'evidence' => [
                    'from' => $from->value,
                    'to' => $to->value,
                    'evidence' => $evidence,
                    'profile_version' => $profile->version,
                    'parser_bindings' => $profile->profile_json['parser_bindings'] ?? [],
                    'observed_at' => $now->toIso8601ZuluString(),
                    'recommended_action' => $to === HealthStatus::ParserDrift
                        ? "Review the evidence, then run `acquisition:discover {$source->key}` (queued automatically if enabled) and approve a new profile version with `acquisition:profile approve`."
                        : 'Watch the next monitoring run; a repeat becomes PARSER_DRIFT.',
                ],
            ]);

            // Entering drift triggers exactly one re-discovery: a candidate, never an activation (plan §14).
            if ($to === HealthStatus::ParserDrift && $from !== HealthStatus::ParserDrift && config('acquisition.self_healing.auto_discover')) {
                $discovery = $this->discovery->prepare($source);
                DiscoveryRun::dispatch($discovery->id);
                $queued = true;
                Log::channel('acquisition')->warning('acquisition.health.rediscovery_queued', ['source_id' => $source->id, 'run_id' => $run->id, 'discovery_run_id' => $discovery->id]);
            }
        } elseif ($from === HealthStatus::RecoveryPending) {
            // The canary after an approved profile passed.
            $to = HealthStatus::Healthy;
            $this->recovered($run, $source, $from, 'Canary monitoring run after profile approval succeeded.');
        } elseif ($from === HealthStatus::Degraded || $from === HealthStatus::ParserDrift) {
            // Two clean runs in a row: the source healed without a new profile.
            if ($this->previousRunStatus($run) === RunStatus::Succeeded) {
                $to = HealthStatus::Healthy;
                $this->recovered($run, $source, $from, 'Two consecutive clean monitoring runs.');
            }
        }

        if ($to !== $from) {
            $source->update(['health_status' => $to]);
        }

        return new HealthVerdict($drift, $evidence, $from, $to, $queued);
    }

    private function recovered(AcquisitionRun $run, Source $source, HealthStatus $from, string $reason): void
    {
        $source->events()->create(['run_id' => $run->id, 'event_type' => 'HEALTH_RECOVERED', 'severity' => 'info', 'evidence' => ['from' => $from->value, 'to' => HealthStatus::Healthy->value, 'reason' => $reason]]);

        $source->events()
            ->whereNull('resolved_at')
            ->whereIn('event_type', ['PARSER_DRIFT', 'SOURCE_DEGRADED'])
            ->update(['resolved_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * Median of the last N observations of a metric for this source (and
     * dimension), excluding the current run. Null until history exists.
     */
    private function baseline(Source $source, string $metric, ?string $dimension, AcquisitionRun $run, int $window): ?float
    {
        $values = HealthObservation::query()
            ->where('source_id', $source->id)
            ->where('metric', $metric)
            ->where('dimension', $dimension)
            ->where(fn ($query) => $query->whereNull('run_id')->orWhere('run_id', '!=', $run->id))
            ->orderByDesc('observed_at')
            ->limit($window)
            ->pluck('value')
            ->map(static fn (mixed $value): float => (float) $value)
            ->sort()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        $count = $values->count();
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function previousRunStatus(AcquisitionRun $run): ?RunStatus
    {
        return AcquisitionRun::query()
            ->where('source_id', $run->source_id)
            ->where('mode', RunMode::Monitoring)
            ->where('id', '<', $run->id)
            ->whereNotIn('status', [RunStatus::Pending, RunStatus::Running])
            ->orderByDesc('id')
            ->value('status');
    }

    /**
     * Representative failed URLs and their RAW artifacts for the drift event.
     *
     * @return list<array{url: string, raw_artifact_id: int|null, error_code: string|null}>
     */
    private function sampleFailures(AcquisitionRun $run): array
    {
        $samples = [];

        $observations = FetchObservation::query()
            ->where('run_id', $run->id)
            ->where(fn ($query) => $query->whereNotNull('error_code')->orWhereNotNull('raw_artifact_id'))
            ->orderBy('id')
            ->limit(5)
            ->get();

        foreach ($observations as $observation) {
            $samples[] = ['url' => $observation->url, 'raw_artifact_id' => $observation->raw_artifact_id, 'error_code' => $observation->error_code];
        }

        return $samples;
    }
}
