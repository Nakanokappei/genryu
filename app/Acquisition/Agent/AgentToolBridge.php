<?php

namespace App\Acquisition\Agent;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolDispatcher;
use App\Acquisition\Tools\ToolError;

/**
 * The one door between the Agent and the platform (ADR-0001, AT-14). Every
 * request from the worker passes here: the tool must be on the allowlist,
 * host scope is forced from the run's budget rather than trusted from the
 * payload, and the result or error is returned in a fixed wire shape.
 */
final class AgentToolBridge
{
    public function __construct(private ToolDispatcher $dispatcher) {}

    /**
     * Envelope: ok, tool, run_id, correlation_id, then either result or error
     * (the ADR-0004 shape).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function call(string $tool, array $payload, AcquisitionRun $run, ?string $correlationId = null): array
    {
        $context = $correlationId !== null ? new ToolContext($run->id, $correlationId) : ToolContext::forRun($run->id);
        $envelope = ['ok' => false, 'tool' => $tool, 'run_id' => $run->id, 'correlation_id' => $context->correlationId];

        try {
            if (! in_array($tool, (array) config('acquisition.agent_tools'), true)) {
                throw new ToolError(ErrorCode::InvalidInput, "Tool {$tool} is not available to the Agent.", ['tool' => $tool, 'available' => config('acquisition.agent_tools')]);
            }

            $result = $this->dispatcher->dispatch($tool, $this->enforceScope($tool, $payload, $run), $context);

            return [...$envelope, 'ok' => true, 'result' => $result->toArray()];
        } catch (ToolError $error) {
            return [...$envelope, ...$error->toArray($context)];
        }
    }

    /**
     * Network-touching tools get the run's host scope, whatever the payload
     * claimed. A run without a scope cannot fetch at all.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enforceScope(string $tool, array $payload, AcquisitionRun $run): array
    {
        if (! in_array($tool, ['fetch_url', 'discover_web'], true)) {
            return $payload;
        }

        /** @var array<string, mixed> $budget */
        $budget = $run->budget ?? [];
        /** @var list<string> $hosts */
        $hosts = $budget['allowed_hosts'] ?? [];

        if ($hosts === []) {
            throw new ToolError(ErrorCode::HostNotAllowed, 'This run has no allowed hosts; network tools are unavailable.', ['run_id' => $run->id]);
        }

        $payload['allowed_hosts'] = $hosts;

        if ($tool === 'discover_web') {
            // Discovery limits come from the run budget too; the Agent may only tighten them.
            foreach (['max_depth', 'max_urls', 'max_seconds'] as $limit) {
                if (isset($budget[$limit])) {
                    $payload[$limit] = isset($payload[$limit]) ? min((int) $payload[$limit], (int) $budget[$limit]) : (int) $budget[$limit];
                }
            }

            $payload['requests_per_minute'] = (int) ($budget['requests_per_minute'] ?? config('acquisition.discovery.requests_per_minute'));
        }

        return $payload;
    }
}
