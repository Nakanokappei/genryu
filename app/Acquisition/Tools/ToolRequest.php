<?php

namespace App\Acquisition\Tools;

/**
 * A validated, typed Tool input. Tools construct these from raw payloads in
 * Tool::parseRequest(), which is where INVALID_INPUT is raised.
 */
interface ToolRequest
{
    /**
     * The request as plain data, used for the audit digest.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
