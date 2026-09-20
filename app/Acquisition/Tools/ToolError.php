<?php

namespace App\Acquisition\Tools;

use App\Acquisition\Domain\Enums\ErrorCode;
use RuntimeException;
use Throwable;

/**
 * The one exception every Tool is allowed to surface (ADR-0004). Whatever
 * goes wrong inside a Tool is converted into this shape before it crosses
 * the Tool boundary, so callers (and the Agent) always see a code, a
 * retryable flag and a correlation ID rather than a library-specific error.
 */
class ToolError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether a retry could plausibly succeed. Derived from the code so the
     * decision is made in exactly one place.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode->isRetryable();
    }

    /**
     * Wrap an arbitrary exception as an INTERNAL tool error. The original
     * stays attached as $previous for logging but its message is not
     * forwarded, since library messages may embed request data.
     */
    public static function internal(Throwable $previous): self
    {
        return new self(ErrorCode::Internal, 'Unexpected failure inside tool: '.$previous::class, [], null, $previous);
    }

    /**
     * The wire representation (ADR-0004), completed with run context.
     *
     * @return array<string, mixed>
     */
    public function toArray(ToolContext $context): array
    {
        return [
            'error' => [
                'code' => $this->errorCode->value,
                'message' => $this->getMessage(),
                'retryable' => $this->isRetryable(),
                'retry_after_seconds' => $this->retryAfterSeconds,
                'details' => $this->details,
                'correlation_id' => $context->correlationId,
                'run_id' => $context->runId,
            ],
        ];
    }
}
