<?php

namespace App\Acquisition\Tools;

/**
 * A ToolResult that is already in wire form. Used by the Agent-facing tools
 * whose output is assembled from several internal results.
 */
final readonly class ArrayResult implements ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
