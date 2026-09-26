# Genryu — Media Management System

Genryu (源流, "headwaters") is a Media Management System that builds articles from primary sources: what public agencies, research institutes and companies publish on their official sites. AI carries each stage — collecting the primary sources, selecting them against the editorial policy, turning them into the parts of an article, writing the article and translating it into several languages, and scheduling its publication. No stage stops to wait for a person; people supervise after the fact.

The first media made with Genryu is **Technology Watch** ([technologywatch.tokyo](https://technologywatch.tokyo)). It follows emerging technology on its way from the laboratory to industry, for readers outside the field. The character of a media is set by its editorial policy; the system itself does not depend on the media.

> Under development.

## Pipeline

| Stage | Screen | What it does |
|---|---|---|
| Collect primary sources | Editorial › Sources | Reads each official site's update list as RSS / Atom, an HTML list or a JSON list. arXiv is read from its RSS per category: papers are judged on their summaries, and the full text is fetched only for those adopted |
| Selection | Editorial › Sources / Documents | **Title filter** (exclude keywords, before fetching) → **Semantic filter** (embeddings measure how much a document is "like this media") → **Screening** (an LLM decides adopt / reject / review; a review is decided again by the next model up) |
| Structuring | Editorial › Materials | Turns a primary source into the parts of an article: the angle, before / change / after, facts, background, figures, who gains and who loses, and what future may come |
| Article generation | Editorial › Articles | Rewrites the headline while scoring it, writes the body under that headline, and checks the structure and length in code. The article is written in the language of the primary source, then translated into each language |
| Production | Production › Quality check / Schedule / Images / Articles | Scores articles against the rubric of the editorial policy, assigns publication slots at local time in each language, draws a top image in a style that fits the hour of publication, and publishes when the time comes |
| Supervision | Supervision › Spot check | Draws 10 documents a day from those the semantic filter measured, for a person to judge "like / unlike". This estimates what the filter misses; the pipeline never waits for it |
| Preview | Preview › Media site | The media as a reader sees it |

Each stage's criteria (the editorial policy) are edited on that stage's screen. The editorial policy prompts are written in English, and the answers come back in the language of the primary source that was read.

## Principles

- **Human on the loop, not in the loop.** Every stage proceeds without waiting for a person; a person reviews afterwards and records verdicts, and those verdicts teach the next decisions. This keeps the time a person spends from growing with the volume processed.
- **No model for what can be done deterministically.** Feed discovery, list reading, checks of length and structure, and slot assignment are done in code. When a headline is scored, the model only scores each item; the code decides pass or fail.
- **Inference is what makes an article worth reading.** Not a paraphrase of the primary source, but who gains from the change, what gets replaced, and what society may follow. Yet no specific prediction with a name or a date is made without the primary source or solid general knowledge behind it.
- **Respect the primary sources.** Every outgoing request obeys robots.txt and waits out its `Crawl-delay` (`User-Agent: Genryu`). Originals are stored as they are, Markdown revisions are kept, and every judgment and material is pinned to the revision it was made from.

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.5), Livewire 4, Flux UI, Fortify |
| Database | PostgreSQL |
| Queue | Database queue (`QUEUE_CONNECTION=database`) |
| Models | OpenAI (Responses API, Embeddings API, Images API) |
| Tests | Pest, Pint, PHPStan |
| Frontend | Vite, Tailwind CSS |

The screens are in Japanese (`APP_LOCALE=ja`, translations in `lang/ja.json`, keyed by the English UI labels used above).

## Getting started

Requirements: PHP 8.5, Composer, Node.js 22, PostgreSQL. On macOS we use [Laravel Herd](https://herd.laravel.com).

```bash
createdb genryu
createdb genryu_test
composer setup
```

Open `.env`, set `OPENAI_API_KEY`, and check `DB_CONNECTION=pgsql` and `DB_DATABASE=genryu`. For cost estimates, set `OPENAI_PRICE_*` to each model's price (US dollars per million tokens).

Start the development server and the queue worker in separate terminals. Without the worker, jobs such as fetching, screening and writing do not run.

```bash
php artisan serve
```

```bash
php artisan queue:work
```

Restart the worker after changing code or `lang/ja.json` (a running worker keeps the old code).

Prompts are not in this repository. A fresh database has none until they are imported with `php artisan prompts:import` from `prompts/`.

## Tests

```bash
composer test
```

Runs Pint, PHPStan and Pest in that order. Tests never touch the network: anything that talks to the outside is tested against fake responses.

## Documents

- `docs/HANDOVER.md` — the purpose of the system and the definition of its stages
- `CLAUDE.md` — the design of each stage and the history of what was decided
- `docs/TODO.md` — what was decided but waits for something
- The rest of `docs/` (Phase 0) — the plan and records of the first acquisition platform, kept as history, not as a guide to the current design
