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

```bash
php artisan acquisition:discover darpa --max-urls=40 --max-tool-calls=40 --max-seconds=240 --hints="..."
```

| 項目 | 結果 |
|---|---|
| run | #3（DISCOVERY、SUCCEEDED、`agent_metadata.candidate_profile_id = 2`） |
| モデル / prompt | `claude-opus-5`、`discovery@1` |
| 所要時間 | 5 分 53 秒（うち `discover_web` 123 秒） |
| tool 呼び出し | 21 回。discover_web 1、fetch_url 8、parse_xml 3、parse_html 3、parse_pdf 2（失敗）、normalize_document 2、store_source_profile_candidate 1 |
| トークン | cache read 322k、cache write 48k、output 13.4k（thinking 2.8k） |
| 候補 | Profile v1（PENDING_APPROVAL）。`change_reason`: "Initial discovery for DARPA. Verified two official RSS feeds, a flat XML sitemap, and server-rendered news/program HTML pages; sampled one news article and one program page end-to-end with passing quality verdicts." |

Agent の判断で評価できる点:

- `/research/programs` が JS 検索 UI でリンクを持たないことを見抜き、program ページは sitemap から列挙すると記録した
- `/rss/opportunities.xml` の 10 件が同一リンク先である caveat を残し、優先度を下げた
- program ページに日付がないため `published_at` を必須にしなかった
- PDF 解析が失敗した際、推測で binding を書かず「UNVERIFIED」として bindings から外した（retryable=false の規則どおり同 URL を再試行していない）
- 運用者ヒントの数値（sitemap 2,400 URL）を「未確認」と明記した

Agent が困った点（Milestone 8 への持ち越し）:

- `parse_xml` の結果（sitemap 2,369 entries）が大きすぎて読めなかった。ブリッジ側で大きな結果を要約する（entries は先頭 N 件 + 件数）必要がある
- `discover_web` は 30 URL の予算で feed / sitemap に到達できず、ヒント経由で見つけた。well-known probe は `/rss.xml` を試すはずだが、budget_exhausted で probe の順番が回らなかった（probe の優先度が hint / archive より低い）。probe を先に回すか、予算に含めない扱いを検討
- robots.txt を読む手段がなかった（text/plain の parser なし）。Discovery Tool が robots の要約を返せば足りる

## 3. Profile 承認

- `parse_pdf` の失敗原因は Tool 側のバグ（入れ子の XMP メタデータで array-to-string）だった。修正後、保存済み RAW #6・#7 を再解析して 3 ページ・10,311 / 7,400 文字を抽出できることを確認
- 運用者が v1 に `application/pdf → pdf.text@1` を加えた候補 v2 を `StorageTool::storeSourceProfileCandidate` で保存（`evidence.operator_notes` に理由を記載）
- 2026-09-21 05:51 UTC、`php artisan acquisition:profile approve darpa 2 --by=nakano.kappei` で v2 を ACTIVE 化。`source_events` に `PROFILE_ACTIVATED`（from null → 2）。v1 は PENDING_APPROVAL のまま履歴として残る

## 4. Monitoring 2 回と idempotency

（実行後に記入: run ID、counters、2 回目で RAW / Revision / NORMALIZED が増えないこと、fetch observation は増えること）

## 5. RAW / Markdown / Revision / Health の確認

（実行後に記入: HTML・XML・PDF 各 1 例の RAW と NORMALIZED、Health observation）

## 6. RAW からのネットワークなし再処理

（実行後に記入: `HTTPS_PROXY` を到達不能にした状態での `acquisition:reprocess --dry-run` と本実行）

## 7. 既知の制約

（実行後に記入）
