<?php

namespace App\Acquisition\Application;

use App\Acquisition\Agent\AcquisitionOrchestrator;
use App\Acquisition\Agent\DiscoveryOutcome;
use App\Acquisition\Agent\DiscoveryTask;
use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Identity\UrlNormalizer;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Source;
use Throwable;

/**
 * Discovery Mode (plan §5.1) as a run: prepare() records the scope and
 * budget on a PENDING run, execute() hands it to the orchestrator and
 * derives the run status from the outcome. A Discovery that stored no
 * candidate is a run with a failure, never a success.
 */
final class DiscoveryRunner
{
    public function __construct(
        private RunLifecycle $lifecycle,
        private AcquisitionOrchestrator $orchestrator,
    ) {}

    /**
     * @param  array<string, int|float>  $budgetOverrides
     */
    public function prepare(Source $source, ?string $seedUrl = null, array $budgetOverrides = [], ?string $hints = null, ?string $script = null): AcquisitionRun
    {
        $profile = $source->activeProfile()->first();
        /** @var array<string, int|float> $budget */
        $budget = [...config('acquisition.discovery'), ...$budgetOverrides];
        $seed = $seedUrl ?? $source->base_url;
        /** @var list<string> $hosts */
        $hosts = $profile?->profile_json['allowed_hosts'] ?? self::defaultHosts($seed);

        return $this->lifecycle->start($source, RunMode::Discovery, $profile, [
            ...$budget,
            'seed_url' => $seed,
            'allowed_hosts' => $hosts,
            'hints' => $hints,
            'script' => $script,
        ], ['orchestrator' => $this->orchestrator::class, 'model' => config('acquisition.worker.model')]);
    }

    public function execute(AcquisitionRun $run): DiscoveryOutcome
    {
        $this->lifecycle->begin($run);
        /** @var array<string, mixed> $budget */
        $budget = $run->budget ?? [];
        /** @var list<string> $hosts */
        $hosts = $budget['allowed_hosts'] ?? [];
        $task = new DiscoveryTask(
            run: $run,
            source: $run->source,
            seedUrl: (string) ($budget['seed_url'] ?? $run->source->base_url),
            allowedHosts: $hosts,
            budget: array_filter($budget, static fn (mixed $value): bool => is_int($value) || is_float($value)),
            hints: isset($budget['hints']) ? (string) $budget['hints'] : null,
        );

        try {
            $outcome = $this->orchestrator->discover($task);
        } catch (Throwable $exception) {
            $this->lifecycle->fail($run, $exception);

            throw $exception;
        }

        $run->update(['agent_metadata' => [
            ...($run->agent_metadata ?? []),
            'model' => $outcome->model ?? $run->agent_metadata['model'] ?? null,
            'tool_calls' => $outcome->toolCalls,
            'usage' => $outcome->usage,
            'cost_usd' => $outcome->costUsd,
            'summary' => $outcome->summary,
            'candidate_profile_id' => $outcome->candidateProfileId,
            'outcome_status' => $outcome->status,
        ]]);

        $counters = new RunCounters;

        if ($outcome->status === DiscoveryOutcome::FAILED) {
            $this->lifecycle->fail($run, $outcome->error ?? 'Discovery failed without a reason.', $counters);

            return $outcome;
        }

        // The whole point of Discovery is a candidate; not producing one is a failure of the run.
        if ($outcome->candidateProfileId === null) {
            $counters->increment(RunCounters::FAILED);
        } else {
            $counters->increment(RunCounters::NEW);
        }

        $this->lifecycle->finish($run, $counters);

        return $outcome;
    }

    /**
     * Without a profile the scope is the seed's host, plus its bare/www twin.
     *
     * @return list<string>
     */
    public static function defaultHosts(string $seedUrl): array
    {
        $host = UrlNormalizer::host($seedUrl) ?? '';
        $twin = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;

        return array_values(array_unique([$host, $twin]));
    }
}
