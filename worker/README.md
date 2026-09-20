# worker — Claude Agent SDK sidecar

The judgement half of acquisition (ADR-0001). Laravel starts it per run:

```text
php artisan acquisition:discover <source>
  -> ClaudeAgentSdkOrchestrator
  -> worker/.venv/bin/python -m acquisition_agent --input in.json --output out.json
       -> Claude Agent SDK, tools = in-process MCP server "acquisition"
       -> each tool call: php artisan acquisition:tool <name> --run=<id> --request=... --response=...
```

The worker never touches the network or the database itself. It has no
built-in tools (`tools=[]`); the only things the Agent can do are the seven
allowlisted tools, and Laravel enforces host scope and budgets on every call.

## Setup

```bash
cd worker
uv sync                 # creates .venv with claude-agent-sdk, python-dotenv, pytest, ruff
cp .env.example .env    # then put ANTHROPIC_API_KEY in .env
```

## Checks

```bash
uv run ruff check acquisition_agent tests
uv run pytest
```

Tests never call the SDK or Laravel; `tests/conftest.py` fakes the artisan
subprocess.

## Script mode (no LLM)

Proves the Laravel <-> worker boundary end to end:

```bash
.venv/bin/python -m acquisition_agent --input in.json --output out.json --script calls.json
```

where `calls.json` is `[{"tool": "discover_web", "payload": {"seed_url": "https://..."}}, ...]`.
The Pest test `WorkerBoundaryTest` (group `worker`) runs exactly this.
