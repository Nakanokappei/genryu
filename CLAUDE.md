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
- The stages are screens in the sidebar: 情報源 (Sources), 文書
  (Documents), 素材情報 (Materials), 記事 (Articles). There is no "update
  entry" entity (decided 2026-09-22): the rows of a source's update list
  are the documents themselves, fetched from the source, kept as the
  original and read into Markdown, all on one row of `documents` (stages
  2.1 and 2.2 of `docs/HANDOVER.md`). Identifiers follow the English UI
  label (see `../CLAUDE.md`): tables `sources`, `documents`, `materials`,
  `articles`.
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
  2026-09-21): a document is listed by `App\Actions\FetchUpdates` (UI
  更新リストを取得 on the source) and fetched by `App\Jobs\FetchDocument`
  (`status` null → fetching → fetched | failed), queued for every newly
  listed document and from the 文書を取得 buttons. The original is
  kept on the `local` disk under `documents/{source}/{entry}.{html|pdf}`;
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
- **The selection layer (取捨選択) is set in two places, not on 編集方針**
  (decided 2026-09-22). The **title filter** (タイトルフィルタ) is on the
  情報源 list screen: when an update list is read only the titles are in
  hand, so exclude keywords are the cheap test; one rule per line,
  several words on a line separated by semicolons must all be in the
  title (掲載 alone would take real news with it), a matching title is
  listed as 対象外 with `excluded_by` = the rule and not fetched; the
  first set of rules was drawn from the 227 titles listed that day. The
  **content filtering** (コンテンツフィルタリング) is on the 文書 screen
  above the list: one body, the developer prompt (OpenAI's name for the
  system prompt) of the スクリーニング, and the model it runs on
  (`EditorialPolicy::MODELS`, column `model`; the other agents use
  `OPENAI_MODEL`).
- **スクリーニング (Screening) is the Editorial Screening Gate** (built
  2026-09-22 from ChatGPT's spec, kept at
  `~/.codex/.chatgpt-projects/…/technology-watch-editorial-screening-gate.md`):
  `App\Jobs\ScreenDocument` (agent `App\Actions\ProposeDecision`, OpenAI
  **Responses API**, the prompt as the developer message with an explicit
  `prompt_cache_breakpoint`, the document after it, structured output)
  decides 採用 / 不採用 / 要確認 (adopt / reject / review) with a reason
  class (`Screening::PRIMARY_REASONS`). Every run is a `screenings` row
  (model, prompt version, tokens with cached / cache-written apart,
  latency, estimated cost from `services.openai.prices`); the document
  points at its latest one (`screening_id`). The prompt text is versioned
  in `screening_prompts` (`ScreeningPrompt::current`, a new version when
  the hash changes). Queued from 未判定の文書をスクリーニング on 文書 and
  スクリーニング on the document (with a model choice, to review with a
  higher model). **Nobody reviews by hand** (decided 2026-09-22): a
  fetched document is screened on its own (`FetchDocument` queues it); a
  要確認 from the first pass gets one second pass (`pass` 2) by the next
  model up (`EditorialPolicy::nextModelUp`), told so after the cached
  prompt and allowed only ADOPT / REJECT; a reject is final. A reject of
  a document with a short body is suspect: the job runs
  `App\Actions\ReviseDocumentSettings` (agent proposal on the original,
  kept only when the body is no longer short, Markdown rebuilt) and
  screens the cured documents again. The gate: `ExtractMaterial` refuses
  a rejected document and the bulk extraction takes adopted ones only.
  Figures per prompt version (rates, cache hit / write rate, cost) are
  on 文書. The seven acceptance cases run only with
  `SCREENING_ACCEPTANCE=1` (real model). `EditorialPolicy::LAYERS` has four layers: exclude_keywords,
  content_filtering, structuring, article.
- **Document Markdown** (`App\Actions\ReadDocument`) reads heading, date,
  body, then fixed text after a `---`; the document title is `#` and body
  headings keep their relative levels from `##` down. Document settings
  have four selectors: content / date / remove / fixed_text (UI 本文 /
  日付 / 除外 / 固定テキスト); the date falls back to `<time>`, a short
  date-looking line, then meta tags; copyright-like paragraphs move to
  the end without settings. A PDF is parsed by `App\Pdf\PdfParser`
  (smalot/pdfparser with secured PDFs decrypted: RC4 / AES-128 / AES-256
  with an empty user password, `App\Pdf\StandardSecurityHandler`; a PDF
  that needs a password fails with a message saying so) and read into the
  same Markdown shape by `App\Pdf\PdfMarkdown` from the positions and
  font sizes of its text: title lines matched against the listed title,
  date line, bigger lines as headings, cells in columns as tables,
  bullets as list items, page numbers dropped. Figures come out as text
  in reading order; there is no cure for a font without a Unicode map.
- **A short body is flagged** (本文が短い, `Document::SHORT_BODY_CHARS` =
  1000, excluded documents aside): a badge on 文書, a callout on the
  document with a link to the source's document settings, a count column
  on 情報源, and on the source's detail a callout naming them with
  短い文書から設定を提案し直す, which has the agent propose settings again
  from a short document's original, keeps them only when they yield a
  longer body, and rebuilds the Markdown (found 2026-09-22 when
  Fraunhofer's selector caught only the teaser and the screening sent 12
  documents to review for lack of a body). AIST's 研究成果 pages are
  short by nature: a warning, not an error.
- **Re-reading documents per source** (情報源 detail): 原本から Markdown を
  作り直す (`App\Actions\RebuildMarkdown`, from the originals on disk, no
  network, synchronous) and 文書をすべて取り直す (queues `FetchDocument`
  for every non-excluded document). A proposed selector that names a page
  number (日立 `#content-17863846`) is generalised to `[id^="content-"]`
  by `FetchDocument::generalise` before it is verified and saved.
- **文書 lists every document with its state** (状態: 取得済み / 取得中 /
  失敗 with the reason as tooltip / 対象外 with the rule as tooltip, — when
  not queued yet; restored 2026-09-22 after a spell of fetched-only),
  sortable and filterable by 情報源 / 公開日 / 形式 / 取得日時 (sort and
  filters in the URL). The source detail lists only the excluded and
  failed ones with their reasons.
- **List screens page by rows per page** (`App\Livewire\PagedList`, the
  base class of 情報源 / 文書; `?rowsPerPage=` in the URL,
  ordered by created_at then id so pages never overlap).
- **Favicons** are fetched by `App\Actions\FetchFavicon` the first time a
  page of the site is in hand and the source has none (configuring it,
  reading its update list, fetching a document), served from the local
  disk by the `sources.favicon` route, and shown by the `pages::favicon`
  component before the title of every document, material and article
  (and before the source's own name on 情報源).
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
