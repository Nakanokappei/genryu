<?php

namespace App\Acquisition\Agent\Tools;

use App\Acquisition\Tools\ToolRequest;

/**
 * Validated but not yet resolved normalize_document payload; resolution
 * needs the run, which only run() knows.
 */
final readonly class AgentNormalizePayload implements ToolRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private array $payload) {}

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
