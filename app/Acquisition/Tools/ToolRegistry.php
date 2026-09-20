<?php

namespace App\Acquisition\Tools;

use App\Acquisition\Domain\Enums\ErrorCode;

/**
 * The allowlist of Tools the platform exposes (AT-14). Anything not
 * registered here cannot be invoked, by the Agent or by anyone else.
 */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param  iterable<Tool>  $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * Resolve a tool by name.
     *
     * @throws ToolError with ErrorCode::InvalidInput for unknown names
     */
    public function get(string $name): Tool
    {
        return $this->tools[$name]
            ?? throw new ToolError(ErrorCode::InvalidInput, "Unknown tool: {$name}.", ['tool' => $name]);
    }

    /**
     * Registered tool names, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
