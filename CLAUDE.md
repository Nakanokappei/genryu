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
- **Human on the loop, not in the loop** (decided 2026-09-22). The pipeline
  never waits for a person: every stage decides on its own (title filter,
  screening with its second pass, extraction, generation), and a person
  supervises after the fact — a daily digest to look at (docs/TODO.md),
  a verdict recorded on a document (人の判定) that overrides and later
  teaches. Otherwise the hours a person puts in would have to grow with
  what the AI processes. Never add a step that blocks on human input.
- The stages are screens in the sidebar, in two groups (decided
  2026-09-23): **編集 (Editorial)** — 情報源 (Sources), 文書 (Documents),
  素材情報 (Materials), 記事 (Articles) — makes an article; **編成
  (Production)** takes the articles on to publication (quality check and
  score against the media's policy, publishing, top image, publication
  time per language, topping up thin days from the best of the rest,
  comments). Each group is a directory under `resources/views/pages`, a
  URL prefix and a route-name prefix (`editorial/…`, `editorial.…`), so
  記事 can be a screen in both. Reading the comments and the audience
  figures may later be a third group, Audience. There is no "update
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
- **Editorial policy lives in the app** (`EditorialPolicy`, one body per
  layer with its model, defaults in the model). **Each layer is set on the
  screen it governs**, not on a page of its own: the title filter on 情報源,
  the content filtering on 文書, the structuring on 素材情報, the article
  generation and the translation on 記事.
- **素材情報 is the parts an article is made of** (stage 2.3, settled
  2026-09-23 after two shapes were tried and rejected the same day).
  `App\Jobs\ExtractMaterial` has `App\Actions\ProposeMaterial` (OpenAI
  **Responses API**, the policy cached with an explicit breakpoint, the
  document after it as material to analyse, structured output) write
  these and nothing else: `angle` (the 切り口 the article would be
  written on), `before` / `change` / `after` (以前はこうだった → 今回ここが
  変わった → この変化が続けばこうなりうる), `facts` (lines the primary
  source gives), `background` (lines from the model's own general
  knowledge), `figures` (図版: the source's images with alt text and
  caption, gathered by `App\Actions\CollectFigures` from the Markdown —
  no model, icons and logos left out by name, absent for a PDF or a page
  without any; added 2026-09-23) and the inference — `winners` (誰が得をするか), `losers`
  (誰が損をするか: not who loses a job, but what is scarce, intermediary
  or incumbent today and is replaced) and `future_society` (どんな未来社会
  が訪れるか, with the conditions it needs). **Every value is a
  sentence that could appear in the article**; where a line stands is
  the key it is under, not an annotation.
  **The inference is the point, not a risk to be minimised** (decided
  2026-09-23): terms and verified facts alone do not reach a general
  reader, and a public that is not reached cannot be asked to fund the
  research — translating that is the job. A material without winners,
  losers and a future is the primary source rewritten, which is not a
  publication. The guard is not omission but grounding: infer from the
  capability, the industry's structure and its costs, and never invent a
  specific with a name or a date on it (○○社が撤退する, △年に価格が半分に)
  without the primary source or solid general knowledge behind it. **The angle is what the article
  claims, not what happened** — asked for first it came back as a
  paraphrase of `change`, so the schema now names it last (a model writes
  the properties in the order the schema gives them, and after the facts
  it has something to claim) and the prompt tests it: could someone say
  "I don't think so"? If not, it is a summary. The prompt carries four
  × / ○ pairs from real documents. A part the model cannot write
  plainly is left out and dropped (`ProposeMaterial::dossier`), because
  "confidence: medium" means we did not want it written —
  `App\Actions\ValidateMaterial` only checks that `angle`, `change` and
  `facts` are there, and a miss is repaired once. Whether the angle is a
  good one is a person's call (人の判定), never the validator's. The
  material pins its document revision, prompt version and model and
  keeps the usage; queued from the 素材情報を抽出 buttons. **The
  structuring prompt and its model are set on the 素材情報 screen**, above
  what they make, as the content filtering is on 文書; 編集方針 keeps the
  article layer and points at the other two. The material is written in
  the language of its primary source and is never translated here: the
  article is written from it in that language and translated afterwards
  (`docs/HANDOVER.md` §3), so pinning the material to one language would
  put a translation in front of the writing itself. Two shapes were tried first and are
  not to be revived: the policy's items each quoted with line numbers
  (a summary in disguise, and the quote checking went with it), and
  ChatGPT's eight-lens dossier with claims, confidence, strength and
  recommended angles (notes about the answer rather than material to
  write with, and five times the cost: ~14k in / ~5k out and two minutes
  against ~3k in / ~1k out and twenty seconds).
- **記事 is written once and translated** (stage 2.4, decided 2026-09-23).
  `App\Jobs\GenerateArticle` (agent `App\Actions\ProposeArticle`, OpenAI
  **Responses API**, the policy cached with an explicit breakpoint, the
  material and its document after it, structured output) writes one
  article **in the language of the material, and so of the primary
  source**, and says which language that was; `App\Jobs\TranslateArticle`
  (agent `App\Actions\ProposeTranslation`) then renders it in each of
  `Article::LANGUAGES` — ja / en / zh-Hant / zh-Hans — that it is not
  already in, queued on its own as soon as the article exists. **A
  translation translates the article, never writes the piece again from
  the material**: what the reporter found in the source survives into
  every language, and one writing plus three translations costs less
  than four writings. So a French source yields a Japanese article and a
  Japanese source does not yield a French one. The translator is handed
  the primary source and the material **as context**, because a
  translator without them mistranslates the terms — a shipping AI
  translator once rendered LLM as 法学修士 — and that context settles
  terms, names and numbers only, never adding to the article or
  correcting it. A material therefore has several `articles` rows: the
  original (`translated_from_id` null, `language` the source's) and its
  translations, each pinning its own prompt version, model and usage.
  **The prompts and models are set on the 記事 screen** (見出し, 記事生成,
  翻訳 — the tabs in the order they are applied), above what
  they make, as the content filtering is on 文書 and the structuring on
  素材情報; **the 編集方針 screen is gone** (2026-09-23) — every layer now
  lives with what it governs. The article screen is one page per piece,
  with the languages as tabs over it.
- **見出しは採点して書き直すループ** (stage 2.4, built 2026-09-23). The
  headline is the hook the whole article rests on and the cheapest thing
  to write again, so it is the one loop in the pipeline, and it is
  bounded. **The headline comes before the body** (reordered 2026-09-23):
  `GenerateArticle::queueFor` queues `App\Jobs\RefineHeadline`, which
  has the headline written from the material in the source language
  (`App\Actions\ProposeHeadline`, in four steps: topic word, plain
  draft, the assumption it rests on, the line that denies it), scores it
  on the material (`App\Actions\ScoreHeadline`), and when it does not
  pass has another written with the review in hand, up to
  `RefineHeadline::ATTEMPTS` (3) headlines. The best-scoring one is kept
  whether or not any passed, with the whole review in
  `articles.headline_review`; no headline fails the article. Then
  `GenerateArticle` writes the body under the settled headline in four
  parts — 起 an opening against the headline (the present, before the
  technology), 承 the background and key words the rest needs, 転 the
  new technology, 結 the world once it is real — and the translations
  follow the body. **The Markdown has one shape** (2026-09-23): the
  headline is the `#` the screen puts above the body, so the body is a
  lead (the whole article in one paragraph), 起 with no heading, 承 / 転 /
  結 under `##`, and `## 出典` with the source as a link; every line is
  set apart by a blank line when it is kept (`Article::separateBlocks`),
  because models write paragraphs one newline apart and Markdown runs
  them together. **The shape and the length are checked in code**
  (`App\Actions\ValidateArticle`): the lead and the opening before the
  first `##`, exactly three `##` sections, no label headings (起…) or
  other levels, text under every heading, and a final 出典 linking the
  source's URL; the length is 800–1,200 characters in Chinese or
  Japanese, 500–800 words otherwise, the sources and the Markdown marks
  left out. A body with problems is written again with them in hand, up
  to `GenerateArticle::MAX_REWRITES` (2) times; the one with the fewest
  problems, then the nearest length, is kept, and what is left is shown
  in its status message. The opening swallowing 承 was the first
  problem it caught. **The topic word
  is the field's own big noun** (decided 2026-09-23): the must once asked
  for a word the reader already knows, which fought the policy and
  failed デジタルツイン and 分解炉; the body's 承 explains the word.
  **The model scores; PHP decides**: the weights, the arithmetic and the
  verdict are in `ScoreHeadline`, so two runs of the same rubric compare
  and a model cannot pass itself by adding up wrongly. Three layers —
  `MUSTS` (pass or fail), `COMMON` (eight items, 80 points between them; 何が可能になるか joined them on 2026-09-23 after a run as a must drove headlines past what the material could vouch for; the length is a must counted in code, 30 characters or 14 words; 常識が覆る moved up from the optional items with 15 points and a bar of its own, `PASS_REVERSED`, after headlines that only summarised the news passed at once),
  `OPTIONAL` (twelve items of 10, of which only the best
  `OPTIONAL_COUNTED` are added, because one article cannot carry them
  all). A headline passes on `PASS_TOTAL` (70), nothing failed, and at
  least `PASS_SPECIFIC` of the 20 for being specific to this article.
  The bar and the rubric were tuned together: eight common items and a
  bar of 80 were cleared by nothing (the best of five articles scored 64
  to 77), and a bar nothing clears costs the full three attempts on every
  article, so 今読む理由 and 利益・損失 moved to the optional items — one
  short line cannot carry them as well — their points were spread over
  the six that remain, and the bar came down to 70. The same five then
  passed, four of them on the headline the writer had already given, and
  25 calls became 7.
  The rubric lives in code because the schema is built from its keys;
  the 見出し layer of the editorial policy says what a headline is for
  and what form it takes, and is edited on 記事. Two things were learned
  at once: "a forecast written as a fact" was too strong a must for a
  headline, which has no room to qualify (dropped, it belongs to the
  body), and a rubric of eight items pushes the model to cram — the form
  rules (one short line, no colon-subtitle, no two claims joined by a
  comma) had to go into the policy before the rewrites read like
  headlines rather than contents pages.
- **品質チェック is the first screen of 編成** (built 2026-09-23,
  `production/quality`). `App\Jobs\CheckQuality` (agent
  `App\Actions\ScoreQuality`, Responses API, the policy cached) scores a
  written original out of 100 against the `quality` layer of the
  editorial policy, **whose rubric lives in the policy text** (edited on
  the screen, unlike the headline's rubric in code), with the material to
  check the facts against, and keeps the score and the reason where the
  points were lost. Every run is a `quality_checks` row (prompt version,
  model, usage), the article shows its latest (`Article::qualityCheck`).
  Queued as soon as `GenerateArticle` has written a body; the screen
  queues the unchecked / failed ones, or all again after the policy
  changes. Translations are not checked. No pass mark yet: it comes with
  the next stage (starting the publication of articles that clear it).
- **スケジュール is the second screen of 編成** (built 2026-09-23,
  `production/schedule`). `App\Actions\ScheduleArticles` (deterministic,
  no model) gives the checked, unpublished originals whose primary source
  was published within the period (対象期間, 7 days; the article's own
  date when the source gave none) the slots of the coming weekdays, best
  quality first, earliest slot first. A slot is a date and a local time of
  day (公開時刻, 07:00 / 09:00 / 12:00 / 15:00 / 18:00, the first
  平日の公開本数 = 5 of them): **every language version goes out at that
  local date and time in its own zone** (`Article::TIMEZONES`: English by
  New York, the rest by their capitals — Tokyo, Beijing as Asia/Shanghai,
  Taipei, Berlin, Paris), stored per row as `articles.scheduled_at` (UTC),
  and a slot is used only when it is still ahead in every zone. A
  translation written later takes its original's slot (`TranslateArticle`,
  and on every run). The settings are one `schedule_settings` row, set on
  the screen; スケジュールを組み直す takes the unpublished articles off first.
  It only sets the times: publishing at them, the top image and topping up
  thin days from below the pass mark come later.
- **Languages are chosen, not fixed** (decided 2026-09-23). We publish
  in seven (`Article::LANGUAGES`, in the order of the countries' 2023 R&D
  spending by UNESCO: en, zh-Hant, ja, de, ko, fr, zh-Hans). A document
  has the language it is written in (`documents.language`), told from
  its text without a model (`App\Actions\DetectLanguage`) when it is
  first read and set right on 文書. **言語設定** at the top of 記事 says,
  per language, whether it takes every source (all), only its own (own)
  or none (`LanguageSetting`); the original is written in the source's
  language whenever some language wants it — as a working copy only when
  its own language does not publish it (`Article::isPublishable`) — and
  translated into every other language that takes all. **言語別の追加
  プロンプト** (same table) holds what belongs to a language rather than
  the article (だ・である調 moved there from the three layers); it is sent
  between the cached policy and the fixed instruction to the headline
  writer and judge, the body writer and the translator alike, the order
  the 記事 screen shows it in. It is read when the call is made, not
  pinned.
- **編成 › 記事** lists the originals on their way out and those out. An
  article moves 公開日時未定 → 画像作成中 → スケジュール済み → 公開済み,
  worked out from what it holds (`Article::publicationStatus`: a time
  from スケジュール, then a top image `image_path`, then `published_at`),
  never kept as a column of its own.
- **画像 draws the top images** (built 2026-09-23, `production/images`).
  `App\Jobs\MakeImage` gives a scheduled original its top image in two
  steps: `App\Actions\ProposeScene` (the `image` layer, Responses API)
  chooses one scene from the world the article says becomes possible,
  fitting the theme of the hour, and `App\Actions\DrawImage` (Images
  API, `EditorialPolicy::imageModel`, default gpt-image-2.5-flare,
  1536×864) draws it with the style of the time band the slot falls in
  and `DrawImage::NEVER` (no text, logos, real people, nothing that
  passes for a photograph). **The style changes with the hour, not the
  score** (decided 2026-09-23: a score-linked look would tell readers
  which articles we rated lower): seven bands (`ImageStyle`: 始業前 06:00
  graphic, お昼休み前 pop, お昼休み中 picture book, 午後 realist painting,
  終業前 abstract, 終業後 retro, 深夜帯 anime), edited on the screen. One
  image serves every language, which go out at the same local time; the
  article keeps the time it was made for (`image_time`), so a slot moved
  to another hour is 画像作成中 again. The source's figures are not given
  to the writer. Every drawing is an `article_images` row with the scene
  and the prompt. Prices are looked up by key (`config('…prices')[$model]`):
  the model ids have dots, which a dot path splits.
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
  (`EditorialPolicy::MODELS`, column `model`). **The developer prompts are
  written in English and answer in the language of the document they
  read** (content filtering, 2026-09-23): the same reasoning in English
  costs about half the tokens of the Japanese it replaced (a screening's
  fixed prompt went from ~2,400 to ~1,000 tokens), and a reason about a
  French document reads better in French. Every stage answers in the
  language of what it read; the screens around them are Japanese.
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
  the hash changes). Queued from 未判定の文書をスクリーニング and
  不採用をもう一度判定する (every reject an older prompt version decided:
  a changed prompt can rescue a document) on 文書, and スクリーニング on
  the document (with a model choice, to review with a higher model). **Nobody reviews by hand** (decided 2026-09-22): a
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
  on 文書. A person's verdict (人の判定, `documents.human_decision` adopt
  / reject with `human_reason`, recorded on the document screen) outranks
  the screening's everywhere (`Document::decision()`); it is kept to
  become a few-shot example for the screening (`docs/TODO.md`). The eight
  acceptance cases run only with `SCREENING_ACCEPTANCE=1` (real model). `EditorialPolicy::LAYERS` has five layers: exclude_keywords,
  content_filtering, structuring, article, translation.
- **A bet is a signal too** (prompt v2, 2026-09-22): the gate rejected
  DARPA's $1M D2 Sprint as EVENT_PR because a prize competition reports
  no results — but a funder putting money, a deadline and a measure
  behind a capability gap it quantifies is itself the sign that the gap
  is now thought solvable, which is what this product is after. The
  content filtering names a second kind of update ("解ける対象になった")
  beside achievements, an ADOPT class `FEASIBILITY_BET` for it (three
  conditions: a concrete capability gap, why now, a concrete commitment
  — **numeric performance targets are not required**, which is what let
  the D2 Sprint through where an earlier 公募 case with hard specs
  passed), and a line in EVENT_PR / ADMINISTRATIVE / PURE_SCIENCE
  sending programs and purpose-built frontier research to it instead.
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
- **Every Markdown a document had is a revision** (`document_revisions`,
  recorded by `Document` on save): a screening and a material pin the
  revision they were made from and read that text, so a quote's line
  numbers stay true when the document is fetched or rebuilt again.
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
- **公開日時 when the source gives one, 公開日 when it does not** (2026-09-23):
  a feed's pubDate names a time and its zone, an HTML list usually gives
  only a day. `FetchUpdates::date` keeps the time only when the zone is
  named too (a bare 10:00 could be any zone, and a guessed instant would
  be shown as a wrong time); `documents.published_has_time` says which it
  is, and `Document::publishedDisplay` shows an instant in the display
  timezone and a day as written — a day is midnight UTC and must not be
  moved to another zone. The detail screen's label follows
  (公開日時 / 公開日); the list columns stay 公開日. The documents listed
  before this cannot get their time back.
- **List screens page by rows per page** (`App\Livewire\PagedList`, the
  base class of 情報源 / 文書 / 素材情報; `?rowsPerPage=` in the URL,
  ordered by created_at then id so pages never overlap).
- **Favicons** are fetched by `App\Actions\FetchFavicon` when a page of
  the site is in hand (configuring it, reading its update list, fetching
  a document); every update list checks the icon again at the URL it came
  from with `If-Modified-Since` (`favicon_url`, `favicon_modified_at`:
  304 keeps it, 200 replaces it, 404 forgets the URL). Served from the
  local disk by the `sources.favicon` route, and shown by the `pages::favicon`
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

## TODO

`docs/TODO.md` lists what was decided but waits for something (the
production deployment, mostly). Add there rather than in code comments.

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
