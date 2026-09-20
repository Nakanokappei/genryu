<?php

namespace App\Console\Commands;

use App\Acquisition\Application\DiscoveryRun;
use App\Acquisition\Application\DiscoveryRunner;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use Illuminate\Console\Command;
use Throwable;

/**
 * acquisition:discover — start Discovery Mode for a source (plan §5.1).
 * Produces a PENDING_APPROVAL profile candidate at best; never activates.
 */
class AcquisitionDiscover extends Command
{
    protected $signature = 'acquisition:discover
        {source : Source key}
        {--seed= : Seed URL (defaults to the source base URL)}
        {--max-depth= : Crawl depth budget}
        {--max-urls= : URL budget}
        {--max-seconds= : Wall-time budget for the crawl tool}
        {--max-tool-calls= : Agent tool-call budget}
        {--hints= : Free-text hints for the Agent}
        {--script= : Replay this JSON list of tool calls in the worker instead of running the Agent (boundary check, no LLM)}
        {--queue : Dispatch to the queue instead of running inline}';

    protected $description = 'Run Discovery Mode for a source and store a Source Profile candidate for approval';

    public function handle(DiscoveryRunner $runner): int
    {
        $source = Source::query()->where('key', $this->argument('source'))->first();

        if ($source === null) {
            $this->error("Unknown source: {$this->argument('source')}");

            return self::INVALID;
        }

        $overrides = [];

        foreach (['max-depth' => 'max_depth', 'max-urls' => 'max_urls', 'max-seconds' => 'max_seconds', 'max-tool-calls' => 'max_tool_calls'] as $option => $key) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $overrides[$key] = (int) $value;
            }
        }

        $seed = $this->option('seed');
        $hints = $this->option('hints');
        $script = $this->option('script');

        if (is_string($script) && $script !== '' && ! is_file($script)) {
            $this->error("Script file not found: {$script}");

            return self::INVALID;
        }

        $run = $runner->prepare(
            $source,
            is_string($seed) && $seed !== '' ? $seed : null,
            $overrides,
            is_string($hints) && $hints !== '' ? $hints : null,
            is_string($script) && $script !== '' ? realpath($script) ?: $script : null,
        );

        if ($this->option('queue')) {
            DiscoveryRun::dispatch($run->id);
            $this->info("Queued discovery run {$run->id}.");

            return self::SUCCESS;
        }

        try {
            $outcome = $runner->execute($run);
        } catch (Throwable $exception) {
            $this->error("Run {$run->id} failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $run->refresh();
        $this->table(['run', 'status', 'outcome', 'tool calls', 'cost (USD)', 'candidate profile'], [[
            $run->id, $run->status->value, $outcome->status, $outcome->toolCalls, $outcome->costUsd ?? '-', $outcome->candidateProfileId ?? '-',
        ]]);

        if ($outcome->summary !== null) {
            $this->line($outcome->summary);
        }

        if ($outcome->error !== null) {
            $this->warn($outcome->error);
        }

        if ($outcome->candidateProfileId !== null) {
            $candidate = SourceProfile::query()->find($outcome->candidateProfileId);
            $this->info("Candidate stored as version {$candidate?->version} (PENDING_APPROVAL). Review it, then run: php artisan acquisition:profile approve {$source->key} {$candidate?->version}");
        }

        return $run->status->value === 'SUCCEEDED' ? self::SUCCESS : self::FAILURE;
    }
}
