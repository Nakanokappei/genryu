from acquisition_agent import runner
from tests.conftest import FakeArtisan


def test_bridge_invokes_artisan_with_the_contracted_command_line(task):
    artisan = FakeArtisan({"fetch_url": {"ok": True, "result": {"status": 200, "raw_artifact": {"blob_uri": "acquisition://raw/ab/cd/x"}}}})
    bridge = runner.make_bridge(task, tool_timeout=30, runner=artisan)

    response, is_error = bridge.call("fetch_url", {"url": "https://www.example.org/"})

    command = artisan.commands[0]
    assert command[:4] == ["/usr/bin/php", "/srv/app/artisan", "acquisition:tool", "fetch_url"]
    assert "--run=7" in command
    assert any(arg.startswith("--request=") for arg in command)
    assert any(arg.startswith("--response=") for arg in command)
    assert artisan.requests[0] == {"url": "https://www.example.org/"}
    assert not is_error
    assert response["result"]["raw_artifact"]["blob_uri"].startswith("acquisition://raw/")
    assert bridge.records[0]["ok"] is True


def test_bridge_reports_tool_errors_as_is_error_without_interpreting_them(task):
    artisan = FakeArtisan({"fetch_url": {"ok": False, "error": {"code": "HOST_NOT_ALLOWED", "message": "nope", "retryable": False}}})
    bridge = runner.make_bridge(task, tool_timeout=30, runner=artisan)

    response, is_error = bridge.call("fetch_url", {"url": "https://evil.example.net/"})

    assert is_error
    assert response["error"]["code"] == "HOST_NOT_ALLOWED"
    assert bridge.records[0]["code"] == "HOST_NOT_ALLOWED"


def test_bridge_wraps_a_missing_response_as_internal(task):
    bridge = runner.make_bridge(task, tool_timeout=30, runner=FakeArtisan(write_response=False))

    response, is_error = bridge.call("discover_web", {"seed_url": "https://www.example.org/"})

    assert is_error
    assert response["error"]["code"] == "INTERNAL"
    assert "boom" in response["error"]["message"]


def test_bridge_enforces_the_tool_call_budget(task):
    bridge = runner.make_bridge(task, tool_timeout=30, runner=FakeArtisan())

    for _ in range(3):
        _, is_error = bridge.call("fetch_url", {"url": "https://www.example.org/"})
        assert not is_error

    response, is_error = bridge.call("fetch_url", {"url": "https://www.example.org/"})

    assert is_error
    assert response["error"]["code"] == "BUDGET_EXHAUSTED"
    assert bridge.calls == 3


def test_bridge_remembers_the_stored_candidate(task):
    artisan = FakeArtisan({"store_source_profile_candidate": {"ok": True, "result": {"profile_id": 42, "version": 1, "status": "PENDING_APPROVAL"}}})
    bridge = runner.make_bridge(task, tool_timeout=30, runner=artisan)

    bridge.call("store_source_profile_candidate", {"profile": {"schema_version": 1}})

    assert bridge.candidate_profile_id == 42
