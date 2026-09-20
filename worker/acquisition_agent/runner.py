"""Runs one Discovery task, either with the Claude Agent SDK or from a script."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from .bridge import BridgeConfig, ToolBridge
from .prompts import PROMPT_VERSION, system_prompt, user_prompt

SCHEMA_RELATIVE_PATH = Path("resources") / "schemas" / "source_profile.v1.schema.json"


def make_bridge(task: dict[str, Any], tool_timeout: int, runner=None) -> ToolBridge:
    """Build the bridge for a task's run from the input document."""
    laravel = task["laravel"]
    config = BridgeConfig(
        php=laravel["php"],
        base_path=laravel["base_path"],
        run_id=int(task["run_id"]),
        max_tool_calls=int(task.get("budget", {}).get("max_tool_calls", 40)),
        timeout_seconds=tool_timeout,
    )
    return ToolBridge(config, runner) if runner else ToolBridge(config)


def outcome(status: str, task: dict[str, Any], bridge: ToolBridge, *, model: str | None, usage: Any = None,
            cost_usd: float | None = None, summary: str | None = None, error: str | None = None) -> dict[str, Any]:
    """The output document Laravel reads (DiscoveryOutcome::fromArray)."""
    return {
        "status": status,
        "candidate_profile_id": bridge.candidate_profile_id,
        "tool_calls": bridge.calls,
        "model": model,
        "prompt_version": PROMPT_VERSION,
        "usage": usage if isinstance(usage, dict) else {},
        "cost_usd": cost_usd,
        "summary": summary,
        "error": error,
        "tool_log": bridge.records,
    }


def run_script(task: dict[str, Any], script: list[dict[str, Any]], bridge: ToolBridge) -> dict[str, Any]:
    """Execute a fixed list of {tool, payload} calls without an LLM.

    Used to prove the Laravel <-> worker boundary end to end; the same code
    path the SDK handlers use, minus the judgement.
    """
    for step in script:
        response, is_error = bridge.call(step["tool"], step.get("payload", {}))
        if is_error and step.get("stop_on_error", True):
            code = (response.get("error") or {}).get("code", "unknown")
            return outcome("failed", task, bridge, model="script", error=f"{step['tool']} failed: {code}")

    return outcome("completed", task, bridge, model="script", summary="Scripted run finished.")


async def run_discovery(task: dict[str, Any], bridge: ToolBridge) -> dict[str, Any]:
    """Let the Agent explore the source, then report what it did."""
    from claude_agent_sdk import ClaudeAgentOptions, ResultMessage, query

    from .tools import build_server

    allowed = list(task.get("tools", []))
    schema_path = Path(task["laravel"]["base_path"]) / SCHEMA_RELATIVE_PATH
    profile_schema = schema_path.read_text(encoding="utf-8") if schema_path.exists() else "{}"
    budget = task.get("budget", {})
    model = task.get("model")

    options = ClaudeAgentOptions(
        model=model,
        system_prompt=system_prompt(task, profile_schema),
        # No built-in tools: the Agent can only act through the bridge (AT-14).
        tools=[],
        mcp_servers={"acquisition": build_server(bridge, allowed)},
        allowed_tools=["mcp__acquisition__*"],
        permission_mode="bypassPermissions",
        # Do not load the repository's .claude/ settings or CLAUDE.md into the Agent.
        setting_sources=[],
        max_turns=int(budget.get("max_turns", 30)),
        max_budget_usd=float(budget.get("max_budget_usd", 2.0)),
        cwd=str(Path(__file__).resolve().parent.parent),
    )

    result: ResultMessage | None = None

    async for message in query(prompt=user_prompt(task), options=options):
        if isinstance(message, ResultMessage):
            result = message

    if result is None:
        return outcome("failed", task, bridge, model=model, error="The Agent produced no result message.")

    subtype = getattr(result, "subtype", "")
    usage = getattr(result, "usage", None)
    cost = getattr(result, "total_cost_usd", None)
    text = getattr(result, "result", None)

    if subtype == "success" and not getattr(result, "is_error", False):
        return outcome("completed", task, bridge, model=model, usage=usage, cost_usd=cost, summary=text)

    if "max_turns" in subtype or "budget" in subtype:
        return outcome("budget_exhausted", task, bridge, model=model, usage=usage, cost_usd=cost, summary=text,
                       error=f"Agent stopped: {subtype}")

    return outcome("failed", task, bridge, model=model, usage=usage, cost_usd=cost, summary=text,
                   error=f"Agent ended with {subtype or 'an error'}: {text or ''}"[:2000])


def load_json(path: str) -> Any:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def write_json(path: str, data: Any) -> None:
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    Path(path).write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
