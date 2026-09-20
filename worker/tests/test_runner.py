import json

import pytest

from acquisition_agent import runner
from acquisition_agent.__main__ import main
from acquisition_agent.prompts import PROMPT_VERSION, system_prompt, user_prompt
from acquisition_agent.tools import TOOL_SPECS, tool_result
from tests.conftest import FakeArtisan


def test_script_mode_runs_the_calls_and_reports_the_candidate(task):
    artisan = FakeArtisan({"store_source_profile_candidate": {"ok": True, "result": {"profile_id": 9, "version": 1, "status": "PENDING_APPROVAL"}}})
    bridge = runner.make_bridge(task, tool_timeout=30, runner=artisan)

    result = runner.run_script(task, [
        {"tool": "discover_web", "payload": {"seed_url": "https://www.example.org/"}},
        {"tool": "store_source_profile_candidate", "payload": {"profile": {"schema_version": 1}}},
    ], bridge)

    assert result["status"] == "completed"
    assert result["candidate_profile_id"] == 9
    assert result["tool_calls"] == 2
    assert result["model"] == "script"
    assert result["prompt_version"] == PROMPT_VERSION
    assert [record["tool"] for record in result["tool_log"]] == ["discover_web", "store_source_profile_candidate"]


def test_script_mode_stops_at_the_first_error(task):
    artisan = FakeArtisan({"fetch_url": {"ok": False, "error": {"code": "ROBOTS_DISALLOWED", "message": "no"}}})
    bridge = runner.make_bridge(task, tool_timeout=30, runner=artisan)

    result = runner.run_script(task, [
        {"tool": "fetch_url", "payload": {"url": "https://www.example.org/private/"}},
        {"tool": "discover_web", "payload": {"seed_url": "https://www.example.org/"}},
    ], bridge)

    assert result["status"] == "failed"
    assert result["error"] == "fetch_url failed: ROBOTS_DISALLOWED"
    assert result["tool_calls"] == 1


def test_main_always_writes_an_outcome_document(task, tmp_path, monkeypatch):
    input_path = tmp_path / "input.json"
    output_path = tmp_path / "output.json"
    script_path = tmp_path / "script.json"
    input_path.write_text(json.dumps(task))
    script_path.write_text(json.dumps([{"tool": "discover_web", "payload": {"seed_url": "https://www.example.org/"}}]))
    original_make_bridge = runner.make_bridge
    monkeypatch.setattr(runner, "make_bridge", lambda t, timeout, runner=None: original_make_bridge(t, timeout, runner=FakeArtisan()))

    exit_code = main(["--input", str(input_path), "--output", str(output_path), "--script", str(script_path)])

    outcome = json.loads(output_path.read_text())
    assert exit_code == 0
    assert outcome["status"] == "completed"
    assert outcome["tool_calls"] == 1


def test_tool_specs_cover_every_agent_tool_and_never_take_bytes():
    assert set(TOOL_SPECS) == {"discover_web", "fetch_url", "parse_html", "parse_xml", "parse_pdf", "normalize_document", "store_source_profile_candidate"}

    for name, spec in TOOL_SPECS.items():
        properties = spec["schema"]["properties"]
        assert "allowed_hosts" not in properties, f"{name} must not let the Agent choose hosts"
        assert not {"html", "xml", "pdf", "body"} & set(properties), f"{name} must not carry bytes"


def test_tool_result_marks_errors_and_carries_the_envelope_verbatim():
    result = tool_result({"ok": False, "error": {"code": "TIMEOUT"}}, True)

    assert result["is_error"] is True
    assert json.loads(result["content"][0]["text"]) == {"ok": False, "error": {"code": "TIMEOUT"}}


def test_prompts_mention_scope_budget_and_pending_approval(task):
    system = system_prompt(task, '{"title": "Source Profile v1"}')
    user = user_prompt({**task, "hints": "look at /news"})

    assert "www.example.org" in system
    assert "PENDING_APPROVAL" in system
    assert '"title": "Source Profile v1"' in system
    assert "look at /news" in user


@pytest.mark.parametrize("subtype,expected", [("success", "completed"), ("error_max_turns", "budget_exhausted"), ("error_during_execution", "failed")])
def test_result_subtypes_map_to_outcome_statuses(task, subtype, expected, monkeypatch):
    """run_discovery's status mapping, exercised with a stubbed SDK module."""
    import sys
    import types

    class ResultMessage:
        def __init__(self, subtype):
            self.subtype = subtype
            self.is_error = subtype != "success"
            self.usage = {"input_tokens": 1}
            self.total_cost_usd = 0.01
            self.result = "summary"

    async def fake_query(prompt, options):
        yield ResultMessage(subtype)

    stub = types.ModuleType("claude_agent_sdk")
    stub.ClaudeAgentOptions = lambda **kwargs: kwargs
    stub.ResultMessage = ResultMessage
    stub.query = fake_query
    stub.tool = lambda *a, **k: (lambda fn: fn)
    stub.create_sdk_mcp_server = lambda **kwargs: kwargs
    monkeypatch.setitem(sys.modules, "claude_agent_sdk", stub)

    import asyncio

    bridge = runner.make_bridge(task, tool_timeout=30, runner=FakeArtisan())
    outcome = asyncio.run(runner.run_discovery(task, bridge))

    assert outcome["status"] == expected
    assert outcome["cost_usd"] == 0.01
