<?php

namespace App\Acquisition\Agent;

use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Source;

/**
 * Everything an orchestrator needs to run Discovery for one source: the
 * run it acts for, the seed, the host scope and the budget (plan §5.1).
 */
final readonly class DiscoveryTask
{
    /**
     * @param  list<string>  $allowedHosts
     * @param  array<string, int|float>  $budget  max_depth, max_urls, max_seconds, requests_per_minute, max_tool_calls, max_turns, max_budget_usd
     */
    public function __construct(
        public AcquisitionRun $run,
        public Source $source,
        public string $seedUrl,
        public array $allowedHosts,
        public array $budget,
        public ?string $hints = null,
    ) {}

    /**
     * The worker's input document.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->run->id,
            'source' => ['key' => $this->source->key, 'name' => $this->source->name, 'base_url' => $this->source->base_url],
            'seed_url' => $this->seedUrl,
            'allowed_hosts' => $this->allowedHosts,
            'budget' => $this->budget,
            'hints' => $this->hints,
            'tools' => config('acquisition.agent_tools'),
        ];
    }
}
