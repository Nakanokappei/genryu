# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Technology Watch
**Primary Source Acquisition Platform — Phase 0**

Continuously discovers and fetches primary sources (official sites such as
DARPA, NEDO), stores the RAW response immutably, normalizes it to Markdown,
and can re-process RAW with newer parsers without touching the network.

> The parent directory's CLAUDE.md (`../CLAUDE.md`) also applies here:
> behavioral guidelines, external-SSD cautions (`._` files, `dot_clean`),
> literate-programming comment style, file-based debug logging.

## The implementation contract

`docs/TECHNOLOGY_WATCH_PHASE0_IMPLEMENTATION_PLAN.md` is the Phase 0 contract.
Read it before any feature work. Non-negotiables it sets:

- **Out of scope for Phase 0:** article generation, technology evaluation,
  public UI, admin UI, search, notifications, billing, user management.
  Do not build them; do not add abstractions for them.
- **Agent judges, Tools execute.** The Agent never touches the DB or blob
  store directly; everything goes through the Storage Tool.
- **RAW is append-only.** Never overwrite stored bytes. Same identity + same
  content hash = no new revision, only a fetch observation.
- **No source-specific branches.** Source knowledge lives in a versioned
  Source Profile (JSON), not in `if ($source === 'darpa')`.
- **Silent failure is failure.** HTTP 200 with empty body, missing required
  fields, or a collapsed entry count is `DEGRADED` / `PARSER_DRIFT`, never
  success.
- **Agent = Claude Agent SDK (Python) in `worker/`, launched by Laravel via
  the `Process` facade** (decided 2026-09-21, see `docs/adr/0001`). Tools are
  PHP; the worker's MCP handlers only proxy to `php artisan acquisition:tool`.
  `AcquisitionOrchestrator` is a port with a deterministic `FakeOrchestrator`
  used by all default tests.
- Milestones run in order (0 → 8). NEDO is the final architecture gate.

## Architecture decisions

`docs/adr/` holds the Milestone 0 decisions. Read the relevant one before
touching its area:

| ADR | Decides |
|---|---|
| 0001 | Agent boundary, Claude Agent SDK sidecar, CLI tool bridge, Fake orchestrator |
| 0002 | `acquisition` disk, content-addressed immutable `BlobStore` (no delete API) |
| 0003 | `stable_key` derivation order, URL normalization, revision rules |
| 0004 | `ErrorCode` enum, retry layers, circuit breaker, partial-failure statuses |
| 0005 | Source Profile JSON Schema v1, status transitions, `ACTIVE` uniqueness |

`docs/acquisition/setup.md` lists local/CI prerequisites and the open items.

## Stack (created 2026-09-21)

| Layer | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.5 via Herd), Livewire 4 + Flux starter kit, Fortify auth |
| Database | PostgreSQL 5432, database `technologywatch`, local user (Homebrew) |
| Tests | Pest 5 (`php artisan test`), Pint, PHPStan (`composer test` runs all three) |
| Frontend | Vite + Tailwind; `npm run build` already produces `public/build` |
| Local URL | http://technologywatch.test (Herd link) — `.claude/launch.json` also has `artisan serve` on 8036 |
| UI locale | `APP_LOCALE=ja`, fallback `en`. Identifiers follow the English UI label (see `../CLAUDE.md`). |

The starter kit's auth screens (login, register, settings, 2FA, passkeys)
came with the scaffold. They are not a Phase 0 deliverable; leave them
dormant rather than extending them.

## Conventions

- Tests never hit the network. Fixture-based tests live under
  `tests/Fixtures/Acquisition/`; live smoke tests are tagged and excluded
  from the default run.
- Store timestamps in UTC; apply timezone only for display.
- Do not log response bodies, secrets, or personal data.
- Commit `CLAUDE.md` and `.claude/launch.json`; `.claude/settings.local.json`
  is per-user and ignored.
