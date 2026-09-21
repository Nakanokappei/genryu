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

### 4.1 第 1 組（run #4・#5、修正前）

| run | 状態 | counters | 所見 |
|---|---|---|---|
| #4 | COMPLETED_WITH_ERRORS | new 22、failed 1、quality_failed 9 | 22 件すべて `/news/features` index から。failed 1 は 17.99MB の PDF（`max_body_bytes` 16MB 超、`BODY_TOO_LARGE`）で、制限どおりの挙動 |
| #5 | COMPLETED_WITH_ERRORS | unchanged 22、failed 1、new 0 | 22 件すべて条件付き GET で 304。新規 RAW・Revision・NORMALIZED はゼロ。fetch observation は 23 件増（AT-05 の文書レベルは成立） |

この 2 run で見つかった問題と対処（コミット「Milestone 6 (3/4)」）:

- **RSS と sitemap が候補を出していなかった。** Discovery が両 URL の RAW を保存していたため Monitoring は条件付き GET を送り 304 を受けたが、再生元の Document（Revision）がまだ存在せず、候補ゼロで素通りしていた。→ 条件付き GET は「その entrypoint の Revision が保存済みの場合」だけ送る
- **PDF 9 件が品質不合格。** すべて `required_fields` の `title` 欠落。DARPA の PDF は Info 辞書に Title を持たない。→ Info に title がなければ 1 ページ目の先頭行を title に使い、`provenance.title_source = first_line` で明示
- **候補の並び。** 2,369 URL の sitemap を 1 run 30 件で消化するには、未知文書を優先しないと同じ 30 件を再確認し続ける。一方で未知だけを優先すると既知文書の再確認が止まる。→ 未知と既知を交互に並べる
- **`/news` index は候補ゼロ。** 保存 RAW を解析すると本文内リンクは 8 本で `/news/20xx/` は 0 本。一覧は JS で描画されており、HTML には載らない。news 記事の列挙経路は RSS（最新 10 件）と sitemap（全件）のみ。Agent の判断（sitemap を第 2 経路にする）は正しかった

### 4.2 第 2 組（run #6・#7、修正後）

| run | 状態 | counters | entrypoint の entry 数（baseline） |
|---|---|---|---|
| #6 | SUCCEEDED | fetched 30 = new 15 + unchanged 15、skipped 1,840、failed 0、quality_failed 0 | rss.xml 10（-）、rss/opportunities.xml 10（-）、sitemap.xml 2,368（-）、/news/features 23（23）、/news 0、/research/programs 0 |
| #7 | SUCCEEDED | fetched 30 = new 15 + unchanged 15、skipped 1,840、failed 0、quality_failed 0 | 同じ 6 entrypoint すべて 304。値は baseline と一致（rss 10/10、sitemap 2,368/2,368 …） |

idempotency（AT-05）の証跡:

- run #7 の既知 15 件は条件付き GET がすべて 304 で `unchanged`。Revision・RAW・NORMALIZED は増えず、fetch observation だけ増えた（139 件）
- 全 58 Document の Revision 数は 1（複数 Revision を持つ Document は 0）。同一内容の再取得で Revision が増えていない
- 新規 15 件は sitemap のバックログからの補充（未知・既知の交互取得）。1,840 件の残りは以降の run で 15 件ずつ消化される見込み
- run #7 で 3 つの XML entrypoint は 304 を受け、保存済み RAW から entries を再生して候補を作った（ネットワークなしの再生）

## 5. RAW / Markdown / Revision / Health の確認

3 形式各 1 例を BlobStore から読み戻し、RAW の SHA-256 が `raw_artifacts.sha256` と一致することを確認した（AT-03）。

| 形式 | Document | RAW | NORMALIZED |
|---|---|---|---|
| HTML | `guid:5501 at https://www.darpa.mil`（news、rev 1） | text/html 43,661B、hash 一致 | `html.generic@1`、title「$3.5M to advance autonomous trauma robotics \| DARPA」、published_at 2026-09-14T18:32:21Z（feed:published 由来）、quality passed |
| XML | `url:https://www.darpa.mil/rss.xml`（feed、rev 1） | text/xml 4,955B、hash 一致 | `xml.feed@1`、outbound_links に 10 記事、quality passed |
| PDF | `url:…/attachment/2025-01/darpa-vignette-arpanet.pdf`（report、rev 1） | application/pdf 410,405B、hash 一致 | `pdf.text@1`、title「DARPA vignettes: ARPANET」（Info 由来）、published_at 2020-07-07（CreationDate）、3 ページ 10,311 文字、quality passed |

Health: run #6・#7 とも `HEALTHY -> HEALTHY`。`health_observations` に entrypoint 別 entry 数（dimension = URL）、run 所要時間、新規/失敗件数、取得成功率が記録され、run #7 以降は baseline（中央値）と比較されている。

## 6. RAW からのネットワークなし再処理

Normalizer を `normalize.document@1` から `@2` に版上げした（feed の pubDate を確度 0.8 の日付候補に、PDF の title 欠落時は 1 ページ目の先頭行を採用）。出力が変わる変更は版を上げるという計画書 §11 の規則に従い、保存済み RAW から `@2` を再生成した。

ネットワーク遮断: `HTTP_PROXY` / `HTTPS_PROXY` に到達不能な `http://127.0.0.1:9` を設定した同じシェルで、まず `Http::get("https://www.darpa.mil/robots.txt")` が `ConnectionException` になることを確認したうえで実行。

```bash
export HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9
php artisan acquisition:reprocess --source=darpa --parser=html.generic@1 --dry-run   # 44 件、1,979,186 B
php artisan acquisition:reprocess --source=darpa --parser=xml.feed@1 --dry-run       # 3 件、404,243 B
php artisan acquisition:reprocess --source=darpa --parser=pdf.text@1 --dry-run       # 11 件、35,390,025 B
php artisan acquisition:reprocess --source=darpa --parser=html.generic@1              # run #8  SUCCEEDED 44/0/0
php artisan acquisition:reprocess --source=darpa --parser=xml.feed@1                  # run #9  SUCCEEDED 3/0/0
php artisan acquisition:reprocess --source=darpa --parser=pdf.text@1                  # run #10 SUCCEEDED 11/0/0
```

結果（`normalized_artifacts` の版別件数と品質合格数）:

| normalizer | parser | 件数 | 合格 |
|---|---|---|---|
| normalize.document@1 | html.generic@1 | 44 | 44 |
| normalize.document@1 | xml.feed@1 | 3 | 3 |
| normalize.document@1 | pdf.text@1 | 11 | **2** |
| normalize.document@2 | html.generic@1 | 44 | 44 |
| normalize.document@2 | xml.feed@1 | 3 | 3 |
| normalize.document@2 | pdf.text@1 | 11 | **11** |

`@1` の成果物は削除・上書きされず併存し、各 NORMALIZED は入力 RAW（`raw_sha256`）、parser、normalizer の版、生成 run（`produced_in_run_id`）へ追跡できる（AT-07）。

## 7. 既知の制約と Phase 1 / Milestone 8 への持ち越し

- **Agent が大きな Tool 結果を読めない。** sitemap（2,368 entries）の `parse_xml` 結果が長すぎて Agent は件数を確認できなかった。ブリッジ側で entries を「先頭 N 件 + 総件数」に要約する必要がある
- **Discovery の well-known probe が予算切れで回らないことがある。** 優先度が hint / archive より低いため、30 URL の予算では `/rss.xml` に届かず、運用者ヒントで補った。probe を先に消化するか予算外にする
- **Agent に robots.txt を読む手段がない。** Discovery Tool の結果に robots の要約（Disallow 一覧、Sitemap 指示）を含めれば足りる
- **JS 描画の一覧ページ**（`/news`、`/research/programs`）は HTML に文書リンクを持たない。計画書 §2.2 のとおりブラウザクローラーは作らず、RSS と sitemap を経路とする。Agent はこれを正しく判断した
- **17.99MB の PDF** は Profile の `max_body_bytes`（16MB）で `BODY_TOO_LARGE` になる。上限を上げるかは運用判断（大きな PDF は年報など）。毎 run 1 件 failed として数えられ続けるので、既知の恒久失敗を候補から除外する仕組み（`document_patterns.exclude` で URL を除くのが現状の手段）が欲しい
- **品質不合格の内容でも Revision が作られる**（Milestone 5 の報告事項）。DARPA では title 対処後に不合格ゼロになったため実害は出なかった。方針は Milestone 8 で決める
- **ADR-0003 の未解決（Revision 判定を RAW の hash で行うリスク）**: run #5〜#7 の既知文書 52 件は全件 ETag による 304 で、byte 比較に至らなかった。DARPA は ETag を返すため Revision の増殖は観測されず。ETag を返さないサイトでの検証は NEDO（Milestone 7）で行う
- **`/rss/opportunities.xml`** は 10 entry が同一リンク先で、feed 文書としてのみ保存される（entry の summary が実体）。公募情報を文書単位で扱うなら Phase 1 の課題
- **バックフィル速度**: 1 run 30 件・毎時実行で、sitemap の残り 1,840 件は約 5 日で消化される。`max_urls_per_run` は Profile の改版（v3 を候補として保存 → 承認）で変更できる
