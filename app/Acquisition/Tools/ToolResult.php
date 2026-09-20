<?php

namespace App\Acquisition\Tools;

/**
 * A typed Tool output. toArray() is the wire form handed to the Agent; it
 * must never contain response bodies, only references and metadata.
 */
interface ToolResult
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
