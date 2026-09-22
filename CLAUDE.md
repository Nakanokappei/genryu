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
- **From 文書 (Documents) onwards nothing is entered by hand** (decided
  2026-09-21): documents are fetched by `App\Jobs\FetchDocument`, queued
  for every new update entry and from the 文書を取得 buttons. The original
  is kept on the `local` disk under `documents/{source}/{entry}.{html|pdf}`;
  Markdown comes from `App\Actions\ReadDocument` with the source's document
  settings (content / remove CSS selectors). When those are missing or no
  longer match, `App\Actions\ProposeDocumentSettings` (OpenAI) proposes new
  ones, which are verified on the page before being saved to the source.
- **Editorial policy lives in the app** (`EditorialPolicy`, screen 編集方針,
  one body per layer: selection / structuring / article; defaults in the
  model). The structuring layer is the prompt of `App\Jobs\ExtractMaterial`
  (agent `App\Actions\ProposeMaterial`, OpenAI); its "- item: …" lines are
  the keys every material must have, checked before the JSON is saved.
  Extraction is queued from the 素材情報を抽出 buttons, not automatically.
- **Articles are generated the same way** (stage 2.4): the article layer
  of the editorial policy is the prompt of `App\Jobs\GenerateArticle`
  (agent `App\Actions\ProposeArticle`, OpenAI), which asks for a title and
  a Markdown body and saves them only when both came back. One article
  per material for now (the output languages of `docs/HANDOVER.md` §3 are
  not built yet); queued from the 記事を生成 buttons. The body is shown
  rendered from Markdown on the article screen.
- **The selection layer (取捨選択) is set on the 更新リスト screen**, not
  on 編集方針: exclude keywords (semicolon separated, applied
  deterministically when the update list is read: a matching title is
  listed as 対象外 with `excluded_by` and no document is queued for it),
  and the criteria for / against fetching, stored for an LLM judge that
  is not built yet. `EditorialPolicy::LAYERS` has five layers.
- **Document Markdown** (`App\Actions\ReadDocument`) reads heading, date,
  body, then fixed text after a `---`; the document heading is `##` and
  body headings keep their relative levels below it. Document settings
  have four selectors: content / date / remove / fixed_text (UI 本文 /
  日付 / 除外 / 固定テキスト); the date falls back to `<time>`, a short
  date-looking line, then meta tags; copyright-like paragraphs move to
  the end without settings.
- **List screens page by rows per page** (`App\Livewire\PagedList`, the
  base class of 情報源 / 更新リスト / 文書; `?rowsPerPage=` in the URL,
  ordered by created_at then id so pages never overlap).
- **Favicons** are fetched by `App\Actions\FetchFavicon` when a source is
  configured (設定をやり直す on an existing source) and served from the
  local disk by the `sources.favicon` route.
- **Restart the worker after changing code or `lang/ja.json`**: a running
  `queue:work` keeps the old classes and translations, so a status message
  saved by the job would stay in English.
- **robots.txt is a global HTTP middleware** (`AppServiceProvider`): every
  outgoing request is checked, a forbidden URL throws
  `App\Exceptions\RobotsForbidden` before anything is sent, and a
  `Crawl-delay` is waited out (`Sleep`) between requests to the host. API hosts we call
  as a client are listed in `config/crawler.php`. New crawler code never
  needs to check robots itself, and must not bypass `Http`.

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
