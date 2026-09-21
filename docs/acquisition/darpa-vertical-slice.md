# DARPA Vertical Slice — 実行証跡（Milestone 6）

作業日: 2026-09-21。環境は `docs/acquisition/setup.md` のローカル環境（開発 DB `technologywatch`）。

## 1. 事前偵察（決定論的、LLM なし）

`curl` と `discover_web` ツール（run #2、`php artisan acquisition:tool discover_web`）で確認した事実。

| 項目 | 結果 |
|---|---|
| robots.txt | あり。`User-agent: *` に Disallow 35 件（`/search/`、`/admin/`、`/user/*`、`/core/` など管理系のみ）。`Sitemap:` 指示なし。`/news`、`/research`、`/sites/default/files/` は許可 |
| `/rss.xml` | RSS 2.0、10 件。GUID は `5501 at https://www.darpa.mil` 形式（`isPermaLink="false"`）、`pubDate`、`dc:creator` あり |
| `/sitemap.xml` | Drupal simple_sitemap の urlset、2,369 URL。内訳: `/news/` 1,138、`/research/` 819、`/about/` 273、`/work-with-us/` 54、`/events/` 30。PDF の `<loc>` は 0 |
| `/sitemap_index.xml`, `/feed`, `/feed.xml`, `/atom.xml` | 404 |
| `/rss` | 200 だが `text/html` の「Redirecting to /rss」ページ（JS リダイレクト） |
| index ページ候補 | `/news`、`/research/programs`、`/news/features`、`/events` など。本文内の同一ホストリンクが 10 本以上 |
| PDF | 26 件を index ページから検出。`/sites/default/files/attachment/<yyyy-mm>/*.pdf` と `/sites/default/files/*.pdf` |
| 記事ページ | Drupal。`<link rel="canonical">` あり。**日付の meta / JSON-LD / `<time>` はなく**、本文冒頭に「Sept. 15, 2026」の平文のみ |

偵察で見つかった実装上の穴と対処（コミット「Milestone 6 (1/2)」）:

- `<main>` 内に `<article>` が複数ある一覧ページで、HTML Tool が最初のカードだけを本文にしていた → 複数 `<article>` の `<main>` は一覧として `<main>` 全体を本文に
- Discovery Tool が本文内リンクしか追跡せず nav の経路を見逃していた → 文書全体のリンクを追跡し、index 判定だけ本文内リンク数で行う
- 日付 meta を持たないサイト向けに、本文冒頭の日付文字列を確度 0.4 の候補として拾う。feed の `pubDate` を確度 0.8 の候補として Normalize まで運ぶ

実データ fixture: `tests/Fixtures/Acquisition/darpa/rss`、`tests/Fixtures/Acquisition/darpa/news-article`（`DarpaFixturesTest`）。

## 2. Discovery Mode（LLM あり）

（実行後に記入: run ID、tool 呼び出し数、費用、候補 Profile の版、Agent の要約、人間による確認事項）

## 3. Profile 承認

（実行後に記入: 承認した版、修正した点、`acquisition:profile approve` の記録）

## 4. Monitoring 2 回と idempotency

（実行後に記入: run ID、counters、2 回目で RAW / Revision / NORMALIZED が増えないこと、fetch observation は増えること）

## 5. RAW / Markdown / Revision / Health の確認

（実行後に記入: HTML・XML・PDF 各 1 例の RAW と NORMALIZED、Health observation）

## 6. RAW からのネットワークなし再処理

（実行後に記入: `HTTPS_PROXY` を到達不能にした状態での `acquisition:reprocess --dry-run` と本実行）

## 7. 既知の制約

（実行後に記入）
