"""Entry point: `python -m acquisition_agent --input in.json --output out.json [--script calls.json]`.

Always writes the output document, even on failure, so Laravel can tell
"the Agent failed" from "the worker crashed" (which leaves no output).
"""

from __future__ import annotations

import argparse
import asyncio
import os
import sys
import traceback
from pathlib import Path

from dotenv import load_dotenv

from . import runner


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="acquisition_agent")
    parser.add_argument("--input", required=True, help="Task document written by Laravel")
    parser.add_argument("--output", required=True, help="Where to write the outcome document")
    parser.add_argument("--script", help="Run this list of tool calls instead of the Agent (no LLM)")
    args = parser.parse_args(argv)

    # worker/.env holds the API key; loaded relative to this file, not cwd.
    load_dotenv(Path(__file__).resolve().parent.parent / ".env")

    task = runner.load_json(args.input)
    tool_timeout = int(os.environ.get("ACQUISITION_TOOL_TIMEOUT", "600"))

    if override := os.environ.get("TW_AGENT_MODEL"):
        task["model"] = override

    bridge = runner.make_bridge(task, tool_timeout)

    try:
        if args.script:
            result = runner.run_script(task, runner.load_json(args.script), bridge)
        else:
            result = asyncio.run(runner.run_discovery(task, bridge))
    except Exception as exception:  # noqa: BLE001 - report every failure as an outcome document
        traceback.print_exc(file=sys.stderr)
        result = runner.outcome("failed", task, bridge, model=task.get("model"),
                                error=f"{type(exception).__name__}: {exception}"[:2000])

    runner.write_json(args.output, result)
    return 0 if result["status"] == "completed" else 1


if __name__ == "__main__":
    sys.exit(main())
