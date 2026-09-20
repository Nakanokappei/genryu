"""Shared fixtures: a fake `php artisan acquisition:tool` that answers from a table."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path
from typing import Any

import pytest


class FakeArtisan:
    """Stands in for subprocess.run. Writes the configured response for a tool
    into the --response path exactly like the real bridge command."""

    def __init__(self, responses: dict[str, dict[str, Any]] | None = None, *, write_response: bool = True) -> None:
        self.responses = responses or {}
        self.write_response = write_response
        self.commands: list[list[str]] = []
        self.requests: list[dict[str, Any]] = []

    def __call__(self, command: list[str], **kwargs: Any) -> subprocess.CompletedProcess[str]:
        self.commands.append(command)
        options = {arg.split("=", 1)[0]: arg.split("=", 1)[1] for arg in command if arg.startswith("--")}
        tool = command[3]
        self.requests.append(json.loads(Path(options["--request"]).read_text(encoding="utf-8")))

        response = self.responses.get(tool, {"ok": True, "result": {"echo": tool}})
        envelope = {"tool": tool, "run_id": int(options["--run"]), "correlation_id": options["--correlation"], **response}

        if self.write_response:
            Path(options["--response"]).write_text(json.dumps(envelope), encoding="utf-8")

        return subprocess.CompletedProcess(command, 0 if envelope.get("ok") else 1, stdout="", stderr="" if self.write_response else "boom")


@pytest.fixture
def task() -> dict[str, Any]:
    return {
        "run_id": 7,
        "source": {"key": "example", "name": "Example Agency", "base_url": "https://www.example.org/"},
        "seed_url": "https://www.example.org/",
        "allowed_hosts": ["www.example.org"],
        "budget": {"max_depth": 2, "max_urls": 20, "max_seconds": 60, "max_tool_calls": 3, "max_turns": 10, "max_budget_usd": 1.0},
        "tools": ["discover_web", "fetch_url", "store_source_profile_candidate"],
        "model": "claude-opus-5",
        "laravel": {"base_path": "/srv/app", "php": "/usr/bin/php"},
    }
