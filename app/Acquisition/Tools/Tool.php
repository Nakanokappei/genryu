<?php

namespace App\Acquisition\Tools;

/**
 * The contract every deterministic Tool implements (plan §7). Tools do
 * exactly what their request says, raise ToolError for every failure, and
 * leave judgement to the Agent.
 */
interface Tool
{
    /**
     * The stable name the Agent calls this tool by, e.g. "fetch_url".
     */
    public function name(): string;

    /**
     * Validate a raw payload into a typed request.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ToolError with ErrorCode::InvalidInput
     */
    public function parseRequest(array $payload): ToolRequest;

    /**
     * Execute the tool. Implementations may throw ToolError; anything else
     * is converted to an INTERNAL ToolError by the dispatcher.
     */
    public function run(ToolRequest $request, ToolContext $context): ToolResult;
}
