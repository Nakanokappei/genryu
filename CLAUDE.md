# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Technology Watch

Builds articles from primary sources: fetch update lists from official
sites, store each document as original + Markdown, extract structured
material (JSON) per the editorial policy, generate articles per the
editorial policy, publish. `docs/HANDOVER.md` is the one-page definition of
the product (purpose, the five stages, stack); read it first.

> The parent directory's CLAUDE.md (`../CLAUDE.md`) also applies here:
> behavioral guidelines, external-SSD cautions (`._` files, `dot_clean`),
> literate-programming comment style, file-based debug logging.

## How we work (decided 2026-09-21)

- **Skeleton first, then one feature at a time, each checked in the browser
  by the user before the next.** Do not build ahead of what has been seen.
- The five stages are screens in the sidebar: 情報源 (Sources), 更新リスト
  (Updates), 文書 (Documents), 素材情報 (Materials), 記事 (Articles).
  Identifiers follow the English UI label (see `../CLAUDE.md`): tables
  `sources`, `updates`, `documents`, `materials`, `articles`.
- Editorial policy is defined per layer (selection, structuring, article
  generation); where it lives in the app is still open.
- Jobs go through the database queue (`QUEUE_CONNECTION=database`); run
  `php artisan queue:work` next to the dev server or nothing happens in the
  background. Workers move to Python with Docker (AWS later). Models are not
  limited to Anthropic: the agent that proposes HTML list settings for a new
  source (`App\Actions\ProposeListSettings`) uses OpenAI (`OPENAI_API_KEY`,
  `OPENAI_MODEL` in `.env`). Deterministic steps (feed discovery, list
  reading) never call a model; an agent proposal is verified on the page
  before it is saved.

## History

The Phase 0 acquisition platform (DARPA / NEDO vertical slices, Claude
Agent SDK sidecar) was set aside on 2026-09-21 to restart from a skeleton
the user can see. It is preserved at git tag `phase0-milestone7`; its plan,
ADRs and evidence stay under `docs/` as history and are not the current
guide. Reuse its ideas or code only when a screen calls for them.

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.5 via Herd), Livewire 4 single-file pages under `resources/views/pages`, Flux UI, Fortify auth |
| Database | PostgreSQL 5432, database `technologywatch` (tests use `technologywatch_test`) |
| Tests | Pest 5, Pint, PHPStan (`composer test` runs all three) |
| Frontend | Vite + Tailwind (`npm run build`) |
| Local URL | http://technologywatch.test (Herd); `.claude/launch.json` has `artisan serve` on 8036 |
| UI locale | `APP_LOCALE=ja`; Japanese strings via `lang/ja.json`, keys are the English UI labels |

The starter kit's auth screens (login, register, settings, 2FA, passkeys)
came with the scaffold; leave them as they are.

## Conventions

- Tests never hit the network.
- Store timestamps in UTC; apply timezone only for display.
- Do not log response bodies, secrets, or personal data.
- Commit `CLAUDE.md` and `.claude/launch.json`; `.claude/settings.local.json`
  is per-user and ignored.

## Gotchas

- **`Http::fake()` keeps the first registered callback.** Registering a
  second callable does not override the first.
- **Pest prints nothing and exits 1** when a PHP fatal error happens while
  loading a class. Run `php -d error_log=/tmp/php-err.log vendor/bin/pest
  <file>` and read the log, or `php -l` the suspect file.
