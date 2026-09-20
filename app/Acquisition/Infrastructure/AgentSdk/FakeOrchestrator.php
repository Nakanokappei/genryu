<?php

namespace App\Acquisition\Infrastructure\AgentSdk;

use App\Acquisition\Agent\AcquisitionOrchestrator;
use App\Acquisition\Agent\AgentToolBridge;
use App\Acquisition\Agent\DiscoveryOutcome;
use App\Acquisition\Agent\DiscoveryTask;

/**
 * Deterministic stand-in for the Agent (ADR-0001). Plays a fixed script of
 * tool calls through the very same bridge the worker uses, so integration
 * tests exercise the platform end to end without an LLM or the network.
 */
final class FakeOrchestrator implements AcquisitionOrchestrator
{
    /** @var list<array<string, mixed>> bridge envelopes, in call order */
    public array $calls = [];

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $script  [tool, payload] pairs, in order
     * @param  string|null  $crash  when set, discover() reports this failure instead of running the script
     */
    public function __construct(
        private AgentToolBridge $bridge,
        private array $script = [],
        private ?string $crash = null,
        private bool $stopOnError = true,
    ) {}

    public function discover(DiscoveryTask $task): DiscoveryOutcome
    {
        if ($this->crash !== null) {
            return DiscoveryOutcome::failed($this->crash);
        }

        $candidateId = null;
        $maxCalls = (int) ($task->budget['max_tool_calls'] ?? PHP_INT_MAX);

        foreach ($this->script as $index => [$tool, $payload]) {
            if ($index >= $maxCalls) {
                return new DiscoveryOutcome(DiscoveryOutcome::BUDGET_EXHAUSTED, $candidateId, count($this->calls), 'fake', [], 0.0, 'Tool call budget exhausted.');
            }

            $response = $this->bridge->call($tool, $payload, $task->run);
            $this->calls[] = $response;

            if ($response['ok'] && $tool === 'store_source_profile_candidate') {
                $candidateId = (int) ($response['result']['profile_id'] ?? 0) ?: null;
            }

            if (! $response['ok'] && $this->stopOnError) {
                return new DiscoveryOutcome(DiscoveryOutcome::FAILED, $candidateId, count($this->calls), 'fake', [], 0.0, null, "{$tool} failed: ".($response['error']['code'] ?? 'unknown'));
            }
        }

        return new DiscoveryOutcome(DiscoveryOutcome::COMPLETED, $candidateId, count($this->calls), 'fake', ['input_tokens' => 0, 'output_tokens' => 0], 0.0, 'Scripted discovery finished.');
    }
}
