"""Calls back into Laravel for every Tool invocation.

One subprocess per call: `php artisan acquisition:tool <name> --run=<id>
--request=<file> --response=<file>`. The response file carries the wire
envelope produced by AgentToolBridge; exit code 1 means the tool reported an
error (already in ADR-0004 shape), anything else unexpected is wrapped as
INTERNAL here. The bridge also counts calls against the run's budget.
"""

from __future__ import annotations

import json
import subprocess
import tempfile
import uuid
from collections.abc import Callable
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

Runner = Callable[..., subprocess.CompletedProcess[str]]


@dataclass(frozen=True)
class BridgeConfig:
    """Where Laravel lives and which run the calls belong to."""

    php: str
    base_path: str
    run_id: int
    max_tool_calls: int
    timeout_seconds: int = 600

    @property
    def artisan(self) -> str:
        return str(Path(self.base_path) / "artisan")


@dataclass
class ToolBridge:
    """Stateful per-run bridge: counts calls and remembers the stored candidate."""

    config: BridgeConfig
    runner: Runner = subprocess.run
    calls: int = 0
    candidate_profile_id: int | None = None
    records: list[dict[str, Any]] = field(default_factory=list)

    def call(self, tool: str, payload: dict[str, Any]) -> tuple[dict[str, Any], bool]:
        """Invoke one tool. Returns (response envelope, is_error)."""
        if self.calls >= self.config.max_tool_calls:
            response = self._error(tool, "BUDGET_EXHAUSTED", f"Tool-call budget of {self.config.max_tool_calls} exhausted.")
            self._record(tool, response)
            return response, True

        self.calls += 1
        correlation = str(uuid.uuid4())

        with tempfile.TemporaryDirectory(prefix="tw-tool-") as directory:
            request_path = Path(directory) / "request.json"
            response_path = Path(directory) / "response.json"
            request_path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")

            command = [
                self.config.php,
                self.config.artisan,
                "acquisition:tool",
                tool,
                f"--run={self.config.run_id}",
                f"--request={request_path}",
                f"--response={response_path}",
                f"--correlation={correlation}",
            ]

            try:
                completed = self.runner(
                    command,
                    cwd=self.config.base_path,
                    capture_output=True,
                    text=True,
                    timeout=self.config.timeout_seconds,
                )
            except subprocess.TimeoutExpired:
                response = self._error(tool, "TIMEOUT", f"Tool bridge call exceeded {self.config.timeout_seconds}s.", correlation)
                self._record(tool, response)
                return response, True

            if response_path.exists():
                try:
                    response = json.loads(response_path.read_text(encoding="utf-8"))
                except json.JSONDecodeError as exception:
                    response = self._error(tool, "INTERNAL", f"Bridge wrote invalid JSON: {exception}", correlation)
            else:
                # Laravel never wrote a response: bootstrap failure, bad run id, or a crash.
                tail = (completed.stderr or completed.stdout or "").strip()[-1500:]
                response = self._error(tool, "INTERNAL", f"No response from bridge (exit {completed.returncode}). {tail}", correlation)

        is_error = not bool(response.get("ok"))

        if not is_error and tool == "store_source_profile_candidate":
            profile_id = (response.get("result") or {}).get("profile_id")
            self.candidate_profile_id = int(profile_id) if profile_id else None

        self._record(tool, response)
        return response, is_error

    def _error(self, tool: str, code: str, message: str, correlation: str | None = None) -> dict[str, Any]:
        return {
            "ok": False,
            "tool": tool,
            "run_id": self.config.run_id,
            "correlation_id": correlation or str(uuid.uuid4()),
            "error": {"code": code, "message": message, "retryable": False, "details": {}},
        }

    def _record(self, tool: str, response: dict[str, Any]) -> None:
        self.records.append(
            {
                "tool": tool,
                "ok": bool(response.get("ok")),
                "code": (response.get("error") or {}).get("code"),
                "correlation_id": response.get("correlation_id"),
            }
        )
