<?php

namespace App\Acquisition\Tools;

use App\Acquisition\Domain\Enums\ToolOutcome;
use App\Acquisition\Domain\Models\ToolInvocation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single entry point for invoking a Tool (ADR-0001). Validates the
 * payload, runs the tool, converts every failure to a ToolError, and writes
 * one tool_invocations row per call. Bodies never reach the log; only the
 * request digest does.
 */
final class ToolDispatcher
{
    public function __construct(private ToolRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ToolError
     */
    public function dispatch(string $toolName, array $payload, ToolContext $context): ToolResult
    {
        $startedAt = hrtime(true);
        $digest = RequestDigest::of($payload);

        try {
            $tool = $this->registry->get($toolName);
            $result = $tool->run($tool->parseRequest($payload), $context);

            $this->record($context, $toolName, $digest, ToolOutcome::Succeeded, null, $startedAt);

            return $result;
        } catch (Throwable $exception) {
            // Anything that is not already a ToolError is an unexpected
            // failure: keep the original for the log, hand out INTERNAL.
            $error = $exception instanceof ToolError ? $exception : ToolError::internal($exception);

            $this->record($context, $toolName, $digest, ToolOutcome::Failed, $error, $startedAt);

            Log::warning('Tool invocation failed', [
                'tool' => $toolName,
                'code' => $error->errorCode->value,
                'retryable' => $error->isRetryable(),
                'run_id' => $context->runId,
                'correlation_id' => $context->correlationId,
                'exception' => $error->getPrevious()?->getMessage(),
            ]);

            throw $error;
        }
    }

    /**
     * Append the audit row for this invocation.
     */
    private function record(ToolContext $context, string $toolName, string $digest, ToolOutcome $outcome, ?ToolError $error, int $startedAt): void
    {
        ToolInvocation::query()->create([
            'run_id' => $context->runId,
            'tool' => $toolName,
            'request_digest' => $digest,
            'outcome' => $outcome,
            'error_code' => $error?->errorCode->value,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            'correlation_id' => $context->correlationId,
        ]);
    }
}
