# NEDO Architecture Test — 実行証跡（Milestone 7）

作業日: 2026-09-21。環境は `docs/acquisition/setup.md` のローカル環境（開発 DB `technologywatch`）。
目的は AT-15: DARPA 固有コードの複製・Source 名分岐・NEDO 専用 pipeline を足さずに、Source 登録と Profile だけで
Discovery → 承認 → Monitoring → RAW → Normalize → Health → Reprocess が通ること。

## 1. 事前偵察（決定論的、LLM なし）

`php artisan acquisition:source add nedo NEDO https://www.nedo.go.jp/`（source #3）のあと、`curl` と
`discover_web` / `fetch_url` / `parse_*` / `normalize_document` を `php artisan acquisition:tool` で直接呼んで確認した事実（run #11、`agent_metadata.note` に operator recon と記録）。

| 項目 | 結果 |
|---|---|
| robots.txt | **404**（HTML の「ページが見つかりません」）。RobotsPolicy は 4xx を「制限なし」と扱う |
| RSS / Atom | **なし**。`/rss.xml`、`/feed`、`/feed.xml`、`/atom.xml`、`/rss` すべて 404。`<link rel="alternate">` もなし |
| `/sitemap.xml` | urlset 1 本、**8,517 URL**、すべて `.html`（PDF 0、sitemap index なし）、`lastmod` は 1,303 件。内訳: `/koubo/` 3,570、`/news/` 1,166、`/english/` 663、`/activities/` 604、`/library/` 567、`/introducing/` 504、`/ugoki/` 419、`/events/` 311。`ETag` と `Last-Modified` あり（静的ファイル）。最新のニュースリリース（`AA5_101965` など）は未掲載 |
| HTML ページ | UTF-8、`lang="ja"`。**`<main>` / `<article>` / `<link rel="canonical">` / 日付 meta / JSON-LD がない**。本文は `div.main-container`。日付は `<h1>` 直下の `<p class="text-right">` に「2026年9月17日」の平文。**`ETag` / `Last-Modified` を返さず、`If-Modified-Since` にも 200 で応答** |
| 同一 HTML の再取得 | 2 回の取得が byte 単位で一致（SHA-256 同一）。条件付き GET が使えなくても RAW hash で Revision 判定できる（ADR-0003 の未解決項目に対する観測） |
| 一覧ページ | `/news/index.html`（最新ニュースリリース 10 件、`<time datetime>` あり、本文内リンク 34）、`/koubo/index.html`（公募、`<time datetime>` あり）、`/form/event.php?f=koubo.html`（公募一覧、`p=N` でページ送り、120 ページ）。`/news/press/index.html`、`/ugoki/index.html` は 404 |
| PDF | `/content/<数字>.pdf`。ページから 49 件検出。`ETag` / `Last-Modified` あり。日本語 PDF（9 ページ、898KB）を `parse_pdf` → 6,413 文字抽出、Info に Title なし → 1 ページ目先頭行を title に採用（normalizer @2 の規則） |

共通ツールの結果（Profile なし、既定の quality expectations）:

| 対象 | parse | normalize |
|---|---|---|
| ニュースリリース `AA5_101965.html` | title「アンモニア燃料によるナフサ分解炉運転で…\| ニュース \| NEDO」、language `ja`、`main_content_selector` = `body`（警告付き）、text 2,710 文字、date candidate `text:leading` 2026-09-17（確度 0.4） | stable_key `url:…/AA5_101965.html`、published_at 2026-09-17、**quality 不合格（`canonical_url` 欠落）** → Profile の `required_fields` を `["title"]` にすれば合格（fixture テストで確認） |
| ニュース一覧 `/news/index.html` | `time[datetime]` 2026-09-17（0.6）、リンク 34 本（うち `/news/press/AA5_*` 10 本） | 一覧として扱う（entrypoint `index`） |
| PDF `/content/100942724.pdf` | 9 ページ、6,413 文字、`has_title` false、CreationDate 2022-02-16 | title を先頭行から採用、quality 合格 |
| `/sitemap.xml` | kind `sitemap`、8,517 entries、応答 JSON 1.1MB | — |

偵察で見つかった共通能力の穴と対処（コミット「Milestone 7 (1/3)」。いずれも DARPA の持ち越し一覧にあった項目で、NEDO でも再現した）:

- **well-known probe が予算切れで回らない**: hint / archive リンクが 40 URL を使い切り、`/sitemap.xml` が試されなかった（DARPA と同じ）→ probe の優先度を sitemap / feed の直下・hint の上に変更。再実行で `/sitemap.xml` を probe 経由で発見（8,517 entries）
- **sitemap の `lastmod` が published_at になっていた**: MonitoringRunner が sitemap entry の `updated` を feed の公開日と同じ確度 0.8 で pipeline に渡していた。NEDO は本文の日付が真で `lastmod` は更新日 → RSS / Atom の entry だけを公開日として渡す
- **Agent が大きな parse 結果を読めない**: Agent 向け parse 結果（`BlobBackedParseTool`）で先頭 50 件を超えるリストを切り、`<key>_total` に総数を残す。PHP 側（Monitoring / Reprocess）の parse は変更なし
- 実データ fixture: `tests/Fixtures/Acquisition/nedo/press-release`、`tests/Fixtures/Acquisition/nedo/news-index`（`NedoFixturesTest`）。fixture は `.gitattributes` で改行正規化から除外（NEDO は CRLF）

偵察で判明した、今回は直さない事項:

- `HostThrottle` のロック待ち超過（`LockTimeoutException`）が `INTERNAL` として返る。偵察中に `discover_web` と `fetch_url` を同じホストへ並行実行したときに発生。運用では 1 Source 1 run なので実害なし。Milestone 8 で `RATE_LIMITED`（retryable）へ寄せる
- `<main>` のないサイトは `body` から nav / footer を除いた残りを本文にするため、先頭にパンくず（「ホーム ニュース …」）が入る。日付検出・品質判定には影響しない。Profile に本文セレクタを持たせるかは Phase 1 の判断

## 2. Discovery Mode（LLM あり）

```bash
php artisan acquisition:discover nedo --max-urls=40 --max-tool-calls=40 --max-seconds=240 --hints="..."
```

運用者ヒントには §1 の事実（feed なし、sitemap の規模、`/news/index.html` と `/koubo/index.html` が一覧、PDF は `/content/<数字>.pdf`、canonical / `<main>` / 日付 meta / ETag なし）を渡した。

| 項目 | 結果 |
|---|---|
| run | #12（DISCOVERY、SUCCEEDED、`agent_metadata.candidate_profile_id = 4`） |
| モデル / prompt | `claude-opus-5`、`discovery@1` |
| 所要時間 | 6 分 9 秒（うち `discover_web` 158 秒、40 URL、budget_exhausted） |
| tool 呼び出し | 19 回。discover_web 1、fetch_url 7、parse_xml 1、parse_html 5、parse_pdf 1、normalize_document 3、store_source_profile_candidate 1。失敗 0 |
| トークン / 費用 | cache read 296k、cache write 97k、output 15.3k（thinking 2.8k）、**1.14 USD** |
| 候補 | Profile v1（PENDING_APPROVAL）。entrypoint 7（index 6、sitemap 1）、document_patterns 9、`text/html` / `application/pdf` / `text/xml` の binding |

Agent の判断で評価できる点:

- 修正後の `discover_web` が probe で見つけた `/sitemap.xml` を「8,517 URL、最新のニュースリリース（2026-09-17）が未掲載なのでバックフィル用」と正しく位置づけ、鮮度の経路として `/form/event.php?f=press.html`（ニュースリリース一覧、889 件・10 件/ページ・89 ページ、`<time datetime>` あり）を自力で見つけて最優先にした
- 公募も同じ仕組みの `/form/event.php?f=koubo.html`（3,481 件・349 ページ）を経路にし、公募ページを `program`、`/library/` と `/content/*.pdf` を `report` と分類した
- 日本語 PDF を fetch → parse_pdf → normalize まで通し、text layer あり（9 ページ、0 ページ欠落）を確認した
- HTML 2 件の normalize が `canonical_url` 欠落だけで不合格になったのを見て、NEDO は canonical を出さないと判断し `required_fields` から外した（ヒントどおり）
- 予算で取得できなかった 2 つの entrypoint を `evidence.unverified_entrypoints` に明記した
- 別ホスト（`green-innovation.nedo.go.jp` など）は「別 Source Profile が必要」として除外した

Agent が誤った点（承認前に運用者が修正、§3）:

- `quality_expectations.required_fields` に `source_url` と `published_at` を入れた。`source_url` は Normalize が判定する field ではないため、このまま承認すると**全文書が品質不合格**になる。スキーマが自由文字列を許していたのが原因 → `required_fields` の要素を enum にし、候補保存で弾くようにした（コミット「Milestone 7 (2/3)」、ADR-0005 に追記）
- `/ugoki/ugoki_index.html` を entrypoint にしたが、実体は 454 バイトの `<meta http-equiv="refresh">` スタブ。Agent はこの URL を取得していない（未検証と明記はしていた）

## 3. Profile 承認

- 運用者が v1 を元に v2 候補を `StorageTool::storeSourceProfileCandidate`（run #12）で保存。差分は `required_fields` → `["title","body"]`、`/ugoki/ugoki_index.html` → 転送先 `/ugoki/tpsearch.html`（curl で 200、`/ugoki/ZZ_*` へ 320 リンク、`<time datetime>` あり）、`/form/event.php?f=info.html` の検証結果。理由は `evidence.operator_notes` に記載
- 2026-09-21 06:59 UTC、`php artisan acquisition:profile approve nedo 2 --by=nakano.kappei` で v2 を ACTIVE 化。v1 は PENDING_APPROVAL のまま履歴として残る
- `max_urls_per_run` は Agent の提案どおり 200 のまま（DARPA は 30）。1 run 約 7〜8 分

## 4. Monitoring 2 回と idempotency

`php artisan acquisition:monitor nedo` を手動で 3 回実行した（scheduler は動かしていない）。

| run | 状態 | counters | 所要時間 | 所見 |
|---|---|---|---|---|
| #13 | COMPLETED_WITH_ERRORS | fetched 200 = new 199 + failed 1、skipped 5,660、quality_failed 27 | 13 分 47 秒 | 7 entrypoint すべて 200。failed 1 は `/koubo/2021_list.html` の 404（sitemap に残る古い URL）。quality_failed 27 はすべて本文 500 文字未満の年別一覧・ハブページ（`/koubo/20xx_list.html`、`/koubo/pastbunya.html`、`/activities/introduction_*.html` など）。`required_fields` は満たしており、Profile の pattern が一覧ページも拾うことが原因（ツールの問題ではない） |
| #14 | COMPLETED_WITH_ERRORS | fetched 200 = new 99 + unchanged 100 + failed 1、skipped 5,660、quality_failed 27 | 13 分 46 秒 | 既知 100 件・未知 100 件の交互取得。HTML は ETag / Last-Modified を返さないため既知 100 件も **200 で全件再取得**したが、RAW hash が一致し Revision は増えなかった |
| #15 | COMPLETED_WITH_ERRORS | fetched 200 = new 95 + unchanged 100 + failed 5、quality_failed 28 | 13 分 46 秒 | 添付取得の最初の実装で実行したが、一覧候補だけで予算 200 を使い切り **PDF は 1 件も取れなかった**（§4.2）。failed 5 は sitemap に残る 404（`/koubo/AB2_100004.html` など） |
| #16 | **FAILED**（運用者が事後に記録） | 129 件取得（PDF 78）後にプロセス停止 | 8 分 41 秒で停止 | 添付を文書の直後に取る修正版。PDF 77 件を取り込んだあと、`memory_limit` 128MB 超過の PHP fatal で停止（§4.3） |
| #17 | **FAILED**（lifecycle が記録） | fetched 120 = new 30 + unchanged 76 + failed 13 | 8 分 27 秒で停止 | メモリ上限修正後。PDF 1 件の Info Title に含まれる不正 UTF-8 で `JsonException` が起き、1 文書の例外が run 全体を止めた（§4.4）。既知 PDF 55 件は ETag により 304 |
| #22 | COMPLETED_WITH_ERRORS | fetched 200 = new 86 + unchanged 97 + failed 17、skipped 5,865、quality_failed 7 | 13 分 46 秒 | 全修正後。PDF 151 件取得のうち既知 73 件は ETag で 304。failed 17 は sitemap 由来の 404 が 16 件と 10MB 超 PDF の `BODY_TOO_LARGE` 1 件。`HEALTHY -> HEALTHY`（§4.5） |

### 4.1 idempotency（AT-05）と ETag なしサイトでの Revision 判定（ADR-0003 の未解決項目）

run #14 の前後でテーブル件数を比較した（全 Source 合計）:

| テーブル | run #14 前 | run #14 後 | 差分 |
|---|---|---|---|
| raw_artifacts | 267 | 366 | +99（新規 99 件分のみ。既知 100 件の再取得は同一 SHA-256 で RAW も増えない） |
| document_revisions | 264 | 363 | +99（`detected_in_run_id = 14` は 99 件 = 新規のみ） |
| normalized_artifacts | 322 | 421 | +99 |
| fetch_observations | 357 | 564 | +207（200 文書 + 7 entrypoint。取得の事実は毎回記録される） |

- NEDO の Document 305 件のうち複数 Revision を持つものは **0**。DARPA では 304 に頼っていた「同一内容で Revision を増やさない」規則が、条件付き GET が効かないサイトでも RAW hash 比較で成立した。ADR-0003 の懸念（動的トークン等で byte が揺れるサイト）は NEDO には当てはまらない（§1 の byte 一致どおり）
- 304 は sitemap.xml の 1 件だけ（ETag あり）。sitemap は保存済み RAW から entries を再生して候補を作った
- entrypoint の entry 数は 7 件すべて baseline と一致（press 10、koubo 10、sitemap 8,517、news 28、info 3、ugoki 320、koubo/index 13）。Health は `HEALTHY -> HEALTHY`
- quality_failed 27 は run #13 と同じ一覧ページ。同一 hash でも Normalize と品質判定は毎回走り、不合格として数えられ続ける（Milestone 5 からの持ち越し事項と同じ）

### 4.2 添付 PDF の取得（run #15 → #16）

NEDO の PDF（`/content/<数字>.pdf`）はプレスリリース・公募・刊行物ページの本文からしかリンクされておらず、sitemap にも一覧にも載らない。Monitoring は entrypoint が列挙した URL しか取得しない設計で、ADR-0005 の `crawl_policy.max_depth` は Profile に書かれるだけで誰も読んでいなかった。run #13〜#15 の 3 回で PDF Document は 0 件だった。

対処（コミット「Milestone 7 (3/4)」、汎用）: `max_depth >= 2` の Profile では、取り込んだ文書の外向きリンクのうち document_patterns に一致するものを**その文書の直後に** 1 段だけ取得し、同じ `max_urls_per_run` に数える。最初の実装は「一覧候補を先に、添付は残り予算で」だったが、候補 5,860 件に対して予算 200 では残りがゼロになり run #15 で PDF は取れなかった。文書直後に取る形に直し、予算 2 件のテストで添付が飢えないことを固定した。Monitoring が pattern の外へ出ることはない（計画書 §5.2）。

### 4.3 run #16 のクラッシュと対処

run #16 は修正版で 129 件（うち PDF 78 件）を取得したあと、`/content/800031213.pdf`（1.9MB、RAW #555 は保存済み）の解析中に PHP fatal（`Allowed memory size of 134217728 bytes exhausted`、smalot/pdfparser の FlateDecode）で停止した。この PDF 単体の解析ピークは 83MB で、run の作業メモリに上乗せされて既定の 128MB を超えた（Herd の CLI は php.ini を読まず PHP 既定値だった）。

見つかった穴と対処（コミット「Milestone 7 (4/5)」）:

- **fatal error で run が RUNNING のまま残る**。catch を通らないため lifecycle の `fail()` が呼ばれず、circuit breaker も進まない → `RunLifecycle::begin` が shutdown フックを登録し、プロセス終了時に run がまだ RUNNING で fatal error があれば FAILED にする（`failIfAbandoned`、テスト付き）
- **メモリ上限**を `config('acquisition.memory_limit')`（既定 1024M、`ACQUISITION_MEMORY_LIMIT`）としてコンソール実行時に適用
- `ParsePdfTool` は画像ストリームを保持しない設定に（text layer だけを使う）
- run #16 は運用者が同じ理由で FAILED に記録し、breaker のリセットを `source_events` に `OPERATOR_NOTE` として残した

run #16 が停止前に取り込んだ PDF 77 件の RAW / Revision / NORMALIZED は正常に保存されており（§5）、停止した 1 件も RAW は保存済み（Revision なし）。

### 4.4 run #17: PDF 文字列の不正 UTF-8 と 1 文書の例外

run #17 は 120 件取得後、`/content/100863534.pdf`（RAW #585、Revision は作成済み）の NORMALIZED 保存で `JsonException: Malformed UTF-8 characters` を投げて FAILED になった。今回は fatal ではなく例外なので lifecycle の `fail()` が正しく動き、breaker も進んだ（運用者がリセット、`OPERATOR_NOTE`）。

原因: PDF の Info `Title`「平成29年度国際エネルギーシステム実証事業_実施方針｜…」の後半が UTF-16 の誤デコードで UTF-8 符号化されたサロゲート符号位置（`ED B8 B0` など）になっていた。`mb_check_encoding` は通るが `json_encode`（= すべての jsonb カラム）は拒否する。日本語 PDF（JUST PDF 3 生成）に固有の現象ではなく、PDF 文字列一般の問題。

対処（コミット「Milestone 7 (5/5)」、汎用）:

- `ParsePdfTool::jsonSafeUtf8`: metadata 値とページ本文を JSON 安全な UTF-8 に正規化（不正部分は U+FFFD）。実際のバイト列でテスト
- `MonitoringRunner::fetchCandidate`: ToolError 以外の例外も**その文書の失敗**（`INTERNAL`、ADR-0004）として fetch observation に記録し、run は続行する。テストは PDF だけで例外を投げる BlobStore を差し込んで、他の文書が取り込まれ run が `COMPLETED_WITH_ERRORS` になることを確認
- 取り残された RAW #585（Revision あり、NORMALIZED なし）は、ネットワーク遮断のまま `acquisition:reprocess --source=nedo --parser=pdf.text@1` で再生した（run #21: reprocessed 1、skipped 97、failed 0）。修正版の Tool で title は「…実施方針｜戰쌰옰꽏揿ऀⴀ猀攀琀ⴀR」（文字化けは元データ由来、UTF-8 として有効）になり、NORMALIZED が保存された。**同じ版の parser でも、成果物が欠けた Revision だけを再処理できる**ことの実証でもある（AT-07）

### 4.5 run #22（全修正後）

添付取得・メモリ上限・shutdown フック・PDF 文字列の正規化・1 文書例外の隔離をすべて含む run。

- 200 件取得のうち PDF が 151 件（添付が本文の直後に取られるため、公募ページ 1 件につき数件の PDF が続く）。既知の PDF 73 件は ETag による 304 で再ダウンロードなし。HTML の既知 24 件は 200 で再取得し hash 一致（unchanged）
- 新規 Revision 86、複数 Revision を持つ文書 0、**NORMALIZED を欠く Revision 0**（run #17 の取り残しは §4.4 の再処理で解消済み）
- failed 17 は sitemap に残る 404（16 件、`/koubo/AT091_*` など）と `BODY_TOO_LARGE`（1 件）で、いずれも文書側の恒久失敗。quality_failed 7 は 500 文字未満の短い一覧 PDF・ページ
- entrypoint の entry 数は 7 件すべて baseline と一致。Health `HEALTHY -> HEALTHY`、breaker 0
- 終了時点の NEDO: Document 611（news 10、program 393+、report 175 PDF を含む）、run #13〜#22 で 1 プロセスのピークメモリは 1024M の上限内

Monitoring の到達状況（AT-05 / AT-16）: 条件付き GET が使えない HTML でも RAW hash で unchanged を判定でき（run #14、#22）、ETag を返す PDF と sitemap では 304 が効き、Revision は同一内容で増えない。

## 5. RAW / Markdown / Revision / Health の確認

3 形式各 1 例を BlobStore から読み戻し、RAW の SHA-256 が `raw_artifacts.sha256` と一致することを確認した（AT-03）。

| 形式 | Document | RAW | NORMALIZED |
|---|---|---|---|
| HTML | `url:https://www.nedo.go.jp/news/press/AA5_101965.html`（news、rev 1） | text/html 23,317B、hash 一致、ETag なし | `html.generic@1` + `normalize.document@2`、title「アンモニア燃料によるナフサ分解炉運転で世界最高水準の混焼率85％を達成しました \| ニュース \| NEDO」、published_at 2026-09-17（本文の「2026年9月17日」、確度 0.4）、language `ja`、2,710 文字、quality passed |
| XML | `url:https://www.nedo.go.jp/sitemap.xml`（feed、rev 1） | text/xml 780,148B、hash 一致、ETag あり | `xml.feed@1`、outbound_links 8,517、quality passed |
| PDF | `url:https://www.nedo.go.jp/content/100942724.pdf`（report、rev 1、run #16） | application/pdf 898,047B、hash 一致、ETag あり | `pdf.text@1`、title「グリーンイノベーション基金事業／CO2等を用いたプラスチック原料製造技術開発プロジェクト」（Info に Title がなく 1 ページ目先頭行）、published_at 2022-02-16（CreationDate）、9 ページ 6,413 文字、quality passed |
| 一覧（entrypoint） | `url:https://www.nedo.go.jp/form/event.php?f=press.html`（other、rev 1） | text/html 27,402B、hash 一致 | title「ニュースリリース一覧 \| NEDO」、published_at 2026-09-17（`time[datetime]`、確度 0.6）、800 文字、quality passed |

§6 の再解析（run #16 直後、RAW 494 件時点）で NEDO の RAW **全件**の SHA-256 が一致した。run #16 の PDF 77 件のうち quality 合格は 73 件（不合格は 500 文字未満の短い一覧 PDF）。

Health: run #13〜#15、#22 は `HEALTHY -> HEALTHY`。`health_observations` に entrypoint 別 entry 数（7 件）、run 所要時間、新規 / 失敗件数、品質合格率、取得成功率が記録され、run #14 以降は baseline（中央値）と比較されている（entry 数は全 entrypoint で baseline と一致）。run #16 は FAILED として breaker が 1 回進み、運用者がリセットした。

## 6. RAW からのネットワークなし再処理

ネットワーク遮断: `HTTP_PROXY` / `HTTPS_PROXY` に到達不能な `http://127.0.0.1:9` を設定した同じシェルで、まず `Http::get("https://www.nedo.go.jp/robots.txt")` が `ConnectionException` になることを確認したうえで実行。

```bash
export HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9
php artisan acquisition:reprocess --source=nedo --parser=html.generic@1 --dry-run   # 416 件、既処理 416
php artisan acquisition:reprocess --source=nedo --parser=xml.feed@1 --dry-run       # 1 件、既処理 1
php artisan acquisition:reprocess --source=nedo --parser=pdf.text@1 --dry-run       # 77 件、既処理 77
php artisan acquisition:reprocess --source=nedo --parser=html.generic@1              # run #18 SUCCEEDED 0 / skipped 416 / 0
php artisan acquisition:reprocess --source=nedo --parser=xml.feed@1                  # run #19 SUCCEEDED 0 / skipped 1 / 0
php artisan acquisition:reprocess --source=nedo --parser=pdf.text@1                  # run #20 SUCCEEDED 0 / skipped 77 / 0
```

NEDO の NORMALIZED は最初から `normalize.document@2` で生成されているため、同じ parser / normalizer の版での再処理は全件 `skipped (existing)` になり、成果物は重複しない（DARPA §6 は @1 → @2 の版上げで新規生成を確認した）。版上げなしで「RAW から network なしで再生できる」ことは、同じ遮断シェルで RAW 494 件を保存済みの parser で再解析して確認した:

| parser | RAW 件数 / バイト | 結果 |
|---|---|---|
| html.generic@1 | 416 / 8.1MB | 416 件すべて本文文字数が保存済み NORMALIZED と一致 |
| pdf.text@1 | 77 / 31.9MB | 77 件すべて一致（解析エラー 0） |
| xml.feed@1 | 1 / 780KB | 解析成功（xml の parse 結果は文字数を持たず、比較は Normalize 側の値で行う） |

RAW の hash 不一致 0、ピークメモリ 99MB（1024M の上限内）。

## 7. 静的レビュー（AT-15）と持ち越し

### 7.1 Source 名分岐・専用コードがないこと

```bash
grep -rniE "darpa|nedo" app/ config/ routes/ database/ worker/acquisition_agent/*.py
```

ヒットは 4 行、すべてコメントか CLI ヘルプの例示（`DiscoverWebTool` の優先度コメント、`Source` モデルの docblock、`acquisition:source` / `acquisition:reprocess` の `e.g. darpa`）。`if ($source === 'darpa')` の類、`Darpa*` / `Nedo*` という名のクラス、Source 別の pipeline は存在しない。

Milestone 6 以降（コミット `51ed13e` 以降）に変更した実装ファイルは 7 件で、いずれも汎用の能力か検証の強化:

| ファイル | 変更 | 動機になった観測 |
|---|---|---|
| `Tools/Discovery/DiscoverWebTool.php` | well-known probe の優先度を hint / archive より上に | DARPA・NEDO 両方で probe が予算切れ |
| `Application/Monitoring/MonitoringRunner.php` | sitemap `lastmod` を公開日として渡さない。`max_depth >= 2` なら取り込んだ文書の pattern 一致リンク（添付）を 1 段取得 | NEDO の日付は本文が真。PDF は本文からしかリンクされない |
| `Application/DocumentPipeline.php`、`Application/IngestOutcome.php` | NORMALIZED の outbound links を IngestOutcome に載せる | 上記の添付取得のため |
| `Agent/Tools/BlobBackedParseTool.php` | Agent 向け parse 結果のリストを 50 件で切り総数を添える | sitemap 8,517 entries（1.1MB）を Agent が読めない |
| `resources/schemas/source_profile.v1.schema.json` | `required_fields` を Normalize の field 名の enum に | Agent 候補の `source_url` 要求 |
| `worker/acquisition_agent/tools.py` | tool 説明にリスト上限を明記 | 同上 |

NEDO 固有の知識は Profile v2（`entrypoints` 7、`document_patterns` 9、`required_fields`、`strip_query_params`）と `evidence` にだけある。DARPA の Profile v2・fixture テスト（`DarpaFixturesTest`）・全 205 テストは変更後も通る（AT-15 の「DARPA test を壊さない」）。

### 7.2 Milestone 8 / Phase 1 への持ち越し

- **一覧ページを文書として取り込む**: `/koubo/[A-Za-z0-9_]+\.html` などの pattern が年別一覧・ハブページも拾い、毎 run 27 件が品質不合格として数えられる。Profile v3 で `exclude` を足すのが正道（運用者の判断）。「不合格でも Revision が作られる」方針の決定（Milestone 8）とあわせて扱う
- **sitemap に残った 404**（`/koubo/2021_list.html` など 5 件）が毎 run failed になる。`/content/100950549.pdf` は Profile の `max_body_bytes`（10MB）超で `BODY_TOO_LARGE`。DARPA の 17.99MB PDF と同じ「既知の恒久失敗を候補から外す仕組み」の要望
- **1 プロセス 1 run のメモリ**: 上限を 1024M に上げたが、10MB の PDF は解析に数百 MB を要しうる。文書ごとの queue job への分割（プロセス隔離）は Milestone 8 の hardening で扱う
- **条件付き GET が使えないサイトの転送量**: 既知文書の再確認が毎回フル GET（HTML 20〜30KB × 100 件/run）。Revision は増えないので正しさに問題はないが、`max_urls_per_run` と実行間隔の設計は運用で決める
- **`<main>` のないサイトの本文抽出**: `body` から nav / footer を除いた残りにパンくずが混じる。Profile に本文セレクタを持たせるかは Phase 1
- **`HostThrottle` のロック待ち超過**が `INTERNAL`（retryable=false）になる。同一ホストへの並行 run でのみ発生。`RATE_LIMITED` に寄せる
- **添付取得は 1 段のみ・同一 run 内のみ**。304 で済んだ既知文書の添付は再評価されない（NEDO は 304 を返さないので毎 run 再評価される）
- **`.docx` / `.xlsx` / `.pptx` の添付**（公募ページ）は parser binding がなく対象外。Phase 0 の範囲外として記録のみ
