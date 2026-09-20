# Technology Watch — Phase 0 実装計画書

**文書バージョン:** 1.0  
**基礎設計:** Primary Source Acquisition Architecture v0.3  
**対象実装者:** Claude Code  
**指定技術:** Laravel + PostgreSQL + Agent SDK  
**最初のVertical Slice:** DARPA  
**次のArchitecture Test:** NEDO

---

## 0. Claude Codeへの実行指示

この文書をPhase 0の実装契約として扱うこと。既存リポジトリがある場合は、最初にコード、設定、テスト、データベース構造、実行環境を調査し、既存の規約を尊重した実装計画へ対応付けること。存在しない機能や依存関係を推測で扱わないこと。

実装中は、小さな完結単位で「実装 → 自動テスト → 結果確認」を繰り返すこと。各マイルストーンの終了時に、変更点、実行したテスト、未解決事項、次の作業を報告すること。

Phase 0の完了条件を満たすまで、記事生成、Technology評価、一般公開UI、管理画面、推薦、検索体験、課金、通知配信へ進んではならない。将来機能のための過剰な抽象化も行わない。

Agent SDKの具体的な製品・パッケージが既存コードまたは環境で確定していない場合、勝手に別製品を選定しないこと。`AcquisitionOrchestrator`をポートとして分離し、決定論的なFake実装でプラットフォームをテスト可能にしたうえで、採用SDKの確認を実装上の明示的なブロッカーとして報告すること。

外部サイトへのアクセスは、利用規約、robots.txt、レート制限、タイムアウト、最大レスポンスサイズ、許可ドメインを考慮すること。認証回避、CAPTCHA回避、アクセス制御の迂回は実装しない。

---

## 1. 目的

Technology WatchのPhase 0では、一次資料を継続的に発見・取得し、原本を失わず保存し、後工程が扱いやすいMarkdownへ正規化し、将来のParser変更時にRAWから何度でも再処理できる **Primary Source Acquisition Platform** を構築する。

このPhaseの成功は「DARPAのページを一度スクレイピングできたこと」ではない。次の能力を実証することである。

1. Agentが未知または変更された公式サイトを探索し、継続監視に適した取得経路を提案できる。
2. 承認済みSource Profileを使い、通常時は低コストかつ再現可能なMonitoringを実行できる。
3. HTML、XML/Feed、PDFをRAWのまま保存し、共通のNormalized Documentへ変換できる。
4. サイト構造の変更や静かな抽出失敗を検知し、誤った空データを正常結果として蓄積しない。
5. Parserを更新しても、ネットワークへ再アクセスせずRAWからNORMALIZEDを再生成できる。
6. DARPA固有コードを複製せず、同じPlatformへNEDOをSourceとして追加できる。

---

## 2. Phase 0のスコープ

### 2.1 作るもの

- Laravel上のPrimary Source Acquisition Agent実行基盤
- Agent SDKを接続するオーケストレーション境界
- Discovery ModeとMonitoring Mode
- Version管理されたSource Profileと承認フロー
- Web Discovery Tool
- HTTP Fetch Tool
- HTML Tool
- XML / Feed Tool
- PDF Tool
- Normalize Tool
- Storage Tool
- PostgreSQLのメタデータ、履歴、監査、Health保存領域
- RAW / NORMALIZED / DERIVEDの三層モデル
- RAW本文を格納するBlob Storage抽象（ローカル開発用ドライバを含む）
- Parser Versioningと再処理コマンド
- Documentの同一性、重複排除、Revision管理
- Silent Failure / `PARSER_DRIFT`検知
- Source Health計測と状態遷移
- DARPAのVertical Slice
- NEDO追加によるArchitecture Test
- CLI、Queue Job、Scheduler、構造化ログ、メトリクス
- Unit / Feature / Integration / Contract / Acceptance Test
- ローカルで再現できるfixtureとセットアップ手順

### 2.2 作らないもの

- 記事生成、要約記事、編集ワークフロー
- Frontier Transition評価、Industrialization評価
- Evidence抽出、Technology候補分類、ランキング
- 読者向けWebサイト、一般公開API、管理UI
- 検索画面、通知配信、課金、ユーザー管理
- PDF内の図表・画像に対する高度なMultimodal解析
- LLMによるHTML/PDF本文抽出を通常経路にすること
- AgentによるSource Profileの無承認本番反映
- 任意JavaScriptを実行する汎用ブラウザクローラー
- Phase 0に不要なマイクロサービス分割

DERIVED層は境界と保存先のみ用意し、Phase 0では業務的な派生データを生成しない。

---

## 3. 設計原則

### 3.1 Agentは判断し、Toolは実行する

Agentの責務は、探索先、候補の優先順位、利用する取得経路、探索継続の要否、Source Profile候補、drift時の復旧候補を判断することである。

Toolの責務は、HTTP通信、解析、変換、hash計算、validation、永続化など、明示的な入力と出力を持つ決定論的処理である。

```text
Agent = Judgment / Planning / Tool Selection
Tool  = Deterministic Execution / Validation / Persistence
```

AgentがデータベースやBlob Storageを直接操作してはならない。すべてStorage Toolを経由する。

### 3.2 AgentはCrawlerではない

Agentは毎回サイト全体を自由探索するCrawlerではなく、Crawlerの取得経路を発見・設定・検証・修復する役割を持つ。一度得た知識はSource Profileへ機械可読な形で保存し、通常運転では同じProfileを再利用する。

### 3.3 安定した経路を優先する

利用可能な場合、原則として次の順に優先する。ただし、網羅性・更新頻度・公式性・履歴取得能力も評価する。

```text
Official API / RSS / Atom
→ Sitemap / Structured XML / JSON-LD
→ Server-rendered HTML index
→ Individual HTML / PDF links
→ Browser-dependent acquisition（明示承認がある場合のみ）
```

### 3.4 原本を捨てない

取得したレスポンスを正規化した後も、RAW本文、主要headers、URL、時刻、hash、取得結果を不変データとして保持する。Markdownだけを唯一の保存形式にしない。

### 3.5 再現性と来歴を優先する

すべてのNORMALIZED/DERIVED artifactは、入力RAW、Parser名・version、設定version、実行runへ追跡可能でなければならない。

### 3.6 失敗を空の成功にしない

HTTP 200やParser例外なしだけで成功と判定しない。抽出本文の消失、件数の急減、必須fieldの欠落、既知selectorやfeed entryの消失を品質異常として扱う。

### 3.7 Source固有知識は設定へ寄せる

URL、対象範囲、feed/index、document pattern、除外条件、期待品質はSource Profileへ格納する。Source名で分岐する`if/else`や、DARPA専用の取得パイプラインを作らない。特殊仕様が共通Toolでは表現できない場合だけ、明示的な拡張ポイントへ小さなAdapterを追加する。

### 3.8 安全なSelf-Healing

drift時にAgentはProfile候補を作成できるが、本番Profileを自動で上書きしない。候補はfixture/backfillで検証し、人間の承認後に新versionとして有効化する。

---

## 4. 論理アーキテクチャ

```text
Laravel Scheduler / CLI / Queue
              │
              ▼
┌─────────────────────────────────────┐
│ Primary Source Acquisition Agent    │
│ Agent SDK + AcquisitionOrchestrator │
└───────────────┬─────────────────────┘
                │ tool calls
   ┌────────────┼───────────────┬──────────────┐
   ▼            ▼               ▼              ▼
Discovery   HTTP Fetch      Parse Tools     Storage Tool
Tool                         HTML/XML/PDF        │
   │            │               │               │
   └────────────┴──────┬────────┘               │
                       ▼                        │
                Normalize Tool                  │
                       │                        │
                       ▼                        ▼
               Validation / Quality     PostgreSQL + Blob Store
                       │                 RAW / NORMALIZED / DERIVED
                       ▼
               Health / Drift Events
```

LaravelはSource、Profile、Run、Document、Revision、Artifact、Health Eventを管理し、Queueで取得・解析・再処理を実行する。大きなRAW本文やPDF byte列をPostgreSQLへ直接詰め込むことを前提にせず、Blob Storage抽象へ保存し、PostgreSQLにはURI、hash、size、media type、provenanceを保持する。開発環境はLaravel filesystemのlocal driverでよい。将来S3互換Storageへ差し替え可能にする。

---

## 5. 実行Mode

### 5.1 Discovery Mode

初回Source追加時、明示的な再探索時、またはHealth異常時に起動する。

1. 公式base URLと許可scopeを確認する。
2. robots.txt、sitemap、feed discovery link、JSON-LD、index、archive、pagination、PDF linkを探索する。
3. 候補経路を公式性、安定性、網羅性、更新時刻、過去資料への到達性、取得コストで評価する。
4. 代表サンプルを取得・解析し、品質検証する。
5. Source Profile Candidateを生成する。
6. backtest結果と差分を添えて`PENDING_APPROVAL`で保存する。
7. 人間の承認後のみ新しいACTIVE versionへ切り替える。

探索にはbudgetを設ける。少なくとも最大depth、最大URL数、最大wall time、許可host、最大body sizeを指定可能にし、無限探索や外部domainへの逸脱を防ぐ。

### 5.2 Monitoring Mode

通常運転ではACTIVEなSource Profileを使う。

1. Conditional GETに使えるETag/Last-Modifiedを読み込む。
2. Profile記載のfeed/sitemap/indexだけを巡回する。
3. document identityと既存Revisionを照合する。
4. 新規または更新候補だけを取得する。
5. RAWを保存し、該当ParserでNORMALIZEDを生成する。
6. validation、quality metrics、Health更新を行う。
7. drift閾値を超えた場合は`PARSER_DRIFT`を記録し、Discovery Modeの再実行候補を作る。

Monitoring Modeから無制限の探索へ暗黙に移行してはならない。再Discoveryは別runとして監査可能にする。

---

## 6. Source Profile

Source ProfileはSourceを継続観測するためのversion付き実行仕様である。コード外に永続化し、JSON Schema等でvalidationする。

最低限、次を表現する。

```json
{
  "schema_version": 1,
  "source_key": "darpa",
  "display_name": "DARPA",
  "profile_version": 1,
  "status": "PENDING_APPROVAL",
  "base_url": "https://www.darpa.mil/",
  "allowed_hosts": ["www.darpa.mil", "darpa.mil"],
  "entrypoints": [
    {"type": "sitemap|rss|atom|index|api", "url": "https://...", "priority": 100}
  ],
  "document_patterns": [
    {"include": "...", "exclude": "...", "document_type": "news|program|report|other"}
  ],
  "content_types": ["text/html", "application/xml", "application/pdf"],
  "parser_bindings": {
    "text/html": "html.generic@1",
    "application/xml": "xml.feed@1",
    "application/pdf": "pdf.text@1"
  },
  "quality_expectations": {
    "required_fields": ["canonical_url", "title"],
    "minimum_text_characters": 200,
    "minimum_item_ratio_to_baseline": 0.5
  },
  "crawl_policy": {
    "max_depth": 3,
    "max_urls_per_run": 500,
    "requests_per_minute": 30,
    "timeout_seconds": 20,
    "max_body_bytes": 52428800
  },
  "discovered_at": "...",
  "last_verified_at": "...",
  "approved_at": null,
  "approved_by": null
}
```

Profileは上書きせずversion追加する。1 SourceにつきACTIVE versionは同時に1つだけとする。変更理由、作成run、検証結果、承認者を保持する。過去runが使用したProfile versionを後から特定できなければならない。

---

## 7. Tool契約

すべてのToolは、型付きrequest/response DTO、schema validation、timeout、構造化error、correlation ID、run IDを持つ。Toolの内部例外を生のままAgentへ返さず、再試行可否を含む共通errorへ変換する。Tool呼び出しは監査ログへ残すが、巨大な本文そのものを通常ログへ出力しない。

### 7.1 Web Discovery Tool

**目的:** 公式サイト内の安定した一次資料公開経路とDocument候補を発見する。

```text
discover_web(seed_url, allowed_hosts, max_depth, max_urls, budget, hints?)
```

**入力:** seed URL、許可host、depth/URL/time budget、既知hint。  
**出力:** canonical URL、robots/sitemap/feed、link、media type候補、pagination/archive、JSON-LD、候補endpoint、探索graph、拒否・失敗理由。  
**制約:** 許可host外へ遷移しない。URL正規化、cycle防止、重複排除を行う。robotsとrate limitを尊重する。

### 7.2 HTTP Fetch Tool

**目的:** Web Resourceをbyte単位で取得し、RAW保存に必要な情報を返す。

```text
fetch_url(url, allowed_hosts, conditional_headers?, limits)
```

**出力必須field:** requested URL、final URL、redirect chain、status、headers、declared/detected content type、retrieved_at、duration、body bytesまたは一時Blob参照、byte length、SHA-256、ETag、Last-Modified。  
**要件:** redirect後もhost policyを再検証する。timeout、retry/backoff、最大redirect、最大size、TLS error、429/Retry-After、5xxを扱う。非2xx、304、content-type mismatchを明示する。

### 7.3 HTML Tool

```text
parse_html(raw_artifact, parser_version, options)
```

**出力:** title、published/updated date候補と根拠、author、canonical URL、main content、headings、links、metadata、Open Graph、JSON-LD、language、Markdown、quality metrics、warnings。  
**原則:** DOM/metadata/readability等の決定論的手法を使う。script/style/navigation等を除外する。日付を推測で確定せず、候補・source・confidenceを保持する。RAW HTMLを変更しない。

### 7.4 XML / Feed Tool

```text
parse_xml(raw_artifact, parser_version, expected_kind?)
```

**対象:** RSS、Atom、Sitemap、汎用XML API。  
**出力:** feed metadata、entry、URL、GUID、published/updated、pagination、sitemap child、warnings、quality metrics。  
**要件:** namespaceを扱う。XXEを無効化し、外部entityを解決しない。不正XMLを空配列の成功として返さない。

### 7.5 PDF Tool

取得はHTTP Fetch Tool、解析はPDF Toolへ分離する。

```text
parse_pdf(raw_artifact, parser_version, options)
```

**出力:** PDF metadata、page count、ページ別text、統合Markdown、抽出方式、暗号化有無、quality metrics、warnings。  
**要件:** RAW PDFを保持する。text layerのないscanを空の成功にしない。Phase 0ではOCRや図表理解を必須とせず、`UNSUPPORTED_SCANNED_PDF`等の明示状態にする。

### 7.6 Normalize Tool

```text
normalize_document(parsed_artifact, source_context, normalizer_version)
```

HTML/XML/PDFの差を吸収し、共通Document ModelとMarkdownを作る。

**共通field:** source、stable document identity、canonical URL、source URL、title、document type、published/updated/retrieved time、language、content type、Markdown body、outbound links、RAW artifact ID、parser/normalizer version、warnings、provenance。  
**要件:** front matterは安定したschemaとfield orderを持つ。変換は同一入力・同一versionなら同一出力となる。NORMALIZED artifactにもSHA-256を付ける。

### 7.7 Storage Tool

```text
store_raw_artifact(...)
upsert_document_identity(...)
append_document_revision(...)
store_normalized_artifact(...)
store_discovery_result(...)
store_source_profile_candidate(...)
record_health_observation(...)
```

**責務:** transaction、idempotency、hash、重複排除、Revision採番、provenance、Blob永続化、DB参照整合性。  
**不変条件:** RAW artifactはappend-only。既存byte列を上書きしない。同一source・同一identity・同一content hashの再取得は新Revisionを作らず、取得観測だけを記録できる。同一identityでcontent hashが変わった場合は新Revisionを作る。

---

## 8. データモデル

名称は既存プロジェクト規約に合わせてよいが、次の概念と関係を保持する。

| Entity | 役割 | 主要field |
|---|---|---|
| `sources` | 監視対象組織 | key, name, base_url, status |
| `source_profiles` | version付き監視仕様 | source_id, version, schema_version, profile_json, status, approved_at/by |
| `acquisition_runs` | Discovery/Monitoring/Reprocess単位 | mode, source_id, profile_id, status, started/finished, budget, counters |
| `tool_invocations` | Agent/Tool監査 | run_id, tool, request_digest, outcome, duration, error_code |
| `discovered_resources` | 探索・監視で見つけたURL | run_id, URL, relation, media type, first/last seen |
| `fetch_observations` | 各HTTP取得の事実 | URL, status, headers, retrieved_at, raw_artifact_id, error |
| `raw_artifacts` | 不変の原本 | blob_uri, SHA-256, bytes, media_type, metadata |
| `documents` | Source内での論理Document | source_id, stable_key, canonical_url, first/last seen |
| `document_revisions` | 内容変更の履歴 | document_id, revision_no, raw_artifact_id, content_hash, detected_at |
| `normalized_artifacts` | Parser出力 | revision_id, parser_id/version, normalizer_version, blob_uri, hash, quality |
| `derived_artifacts` | 将来の派生結果の境界 | normalized_id, derivation_type/version, blob_uri |
| `health_observations` | 計測値 | source_id, run_id, metric, value, baseline, status |
| `source_events` | drift・停止・復旧 | source_id, run_id, event_type, severity, evidence, resolved_at |

主要JSON fieldにはschema versionを付ける。検索・運用に必要なfieldはJSONだけへ閉じず、適切なcolumn/indexを設ける。少なくともsource/status、run/status/time、document identity、hash、profile version、unresolved eventへindexを設定する。

---

## 9. RAW / NORMALIZED / DERIVED

### 9.1 RAW

HTTP responseの原本。HTML/XML/JSON/PDF byte列、request/final URL、redirect、status、必要なheaders、取得時刻、media type、size、SHA-256を保存する。原則immutableかつappend-onlyとする。

### 9.2 NORMALIZED

RAWをversion付きParser/Normalizerで変換した共通Document ModelとMarkdown。後工程の標準入力である。RAWとの参照を必須にし、複数Parser versionの結果を併存可能にする。

### 9.3 DERIVED

将来のEvidence、Entity、Technology候補、要約、評価等。Phase 0ではschema境界とprovenance原則のみ定義し、生成処理は実装しない。NORMALIZEDを更新してもDERIVEDを暗黙に上書きせず、将来の再生成対象として識別できる設計にする。

---

## 10. Document IdentityとRevision

URLだけをDocument identityにしない。Feed GUID、公式ID、canonical URL、安定したURL pattern等を優先順位付きで利用し、Source内の`stable_key`を作る。Tracking query、fragment、既知の無意味な末尾slash差は正規化するが、意味のあるqueryを消さない。

- 初見のstable key: DocumentとRevision 1を作成する。
- 同一stable key・同一content hash: 新Revisionを作らず、fetch observationとlast_seenを更新する。
- 同一stable key・異なるcontent hash: Revisionを追加する。
- 異なるURLが同一canonical/GUIDを示す: 同一Document候補として扱い、判定根拠を保持する。
- identity衝突が曖昧: 自動mergeせず、review可能なeventを作る。

RevisionにはRAWとNORMALIZEDの両方を追跡できるようにする。過去Revisionを削除・上書きしない。

---

## 11. Parser Versioningと再処理

Parser IDは種類とversionを分け、例として`html.generic@1`、`xml.feed@1`、`pdf.text@1`のように識別する。versionは実装変更により出力が変わり得る場合に上げる。コードのGit SHA、設定version、library versionも実行metadataへ残す。

次の再処理機能をCLI/Queueで提供する。

```text
acquisition:reprocess
  --source=<key>
  --parser=<id@version>
  --from=<date?>
  --to=<date?>
  --document=<stable-key?>
  --dry-run
```

再処理は保存済みRAWだけを読み、原則として外部networkへアクセスしない。新しいNORMALIZED artifactを追加し、旧versionを保持する。dry-runでは対象件数、推定処理量、変更予定を表示する。失敗はdocument単位で記録し、run全体の一部失敗を隠さない。

---

## 12. Silent Failureと`PARSER_DRIFT`

次のような「通信は成功したが取得品質が壊れた」状態を検知する。

- HTTP 200だが本文がlogin/error/challenge/空ページ
- 抽出文字数が絶対閾値未満または過去baselineから急減
- title、canonical URL、本文等の必須fieldが欠落
- Feed/Sitemap entry数がbaselineから不自然に減少
- 既知indexのlink patternが消失
- content typeが期待値から変化
- 重複率、parse warning率、失敗率が急増
- 一定期間、新規Documentがなく、同Sourceの公開活動と矛盾する可能性がある
- PDFが画像のみ、暗号化、または抽出不能

単一指標だけで自動修復を確定せず、複数のevidenceと連続runを考慮できるようにする。ただし重大な欠落は1回でもrunを`DEGRADED`/`FAILED`にできる。

`PARSER_DRIFT` eventには、観測値、baseline、使用Profile/Parser version、代表失敗URL、RAW artifact、発生時刻、推奨actionを含める。driftを検知したrunを成功扱いしない。

---

## 13. Source Health

Sourceごとに少なくとも次を計測する。

- fetch success rate / HTTP status分布
- parse success rate
- required-field completeness
- extracted text lengthの分布
- discovered/fetched/new/revised Document数
- duplicate ratio
- warning/error rate
- freshness（最後の正常取得・新規Document）
- Monitoring所要時間と取得量
- Profile最終検証時刻

Health状態は例として次を使う。

```text
HEALTHY → DEGRADED → PARSER_DRIFT → RECOVERY_PENDING → HEALTHY
                    └────────────→ DISABLED（人間の判断）
```

状態遷移は理由とevidenceを伴う。Health異常は構造化ログとDB eventへ記録し、Schedulerが同じ失敗を無限再試行しないようbackoff/circuit breakerを設ける。Phase 0では通知UIは作らないが、運用システムが読めるexit code、log、eventを提供する。

---

## 14. Self-Healingフロー

```text
Monitoring
→ quality低下を検知
→ PARSER_DRIFT event
→ 現行Profileを維持したままDiscovery Runを起動
→ 新しい経路/Profile Candidateを作成
→ 保存済みfixture + live sampleでbacktest
→ 現行Profileとの比較レポート
→ Human Approval
→ 新Profile versionをACTIVE化
→ Monitoring canary
→ Health回復を確認
```

Agentは候補を作成するだけで、承認前に本番Profileを上書きしない。新Profileが失敗した場合、直前のversionへ切り戻せること。Profile切替自体も監査記録へ残す。

---

## 15. 実装順序

順序を入れ替えてUIや後工程へ進まないこと。

### Milestone 0 — Repository調査とArchitecture Decision

- 既存Laravel version、PHP、PostgreSQL、Queue、Storage、test frameworkを確認する。
- 採用するAgent SDKと接続方式を確認する。
- ADRを作成し、Agent境界、Blob Storage、identity、retry、Profile schemaを確定する。
- ローカル実行とCIの前提を文書化する。

**Gate:** 未確定事項が実装を左右する場合は明示して止める。記事/UIへ逃げない。

### Milestone 1 — Domain ModelとPersistence

- migration、model、enum、constraint、indexを実装する。
- Blob Store portとlocal driverを実装する。
- RAW immutable、Revision、Profile versionの不変条件をtestする。
- fixtureを用意する。

### Milestone 2 — 決定論的Tool群

- HTTP Fetch、HTML、XML/Feed、PDF、Normalize、Storageを順に実装する。
- 外部networkに依存しないfixture-based testを作る。
- Tool contract、error taxonomy、idempotencyをtestする。

### Milestone 3 — Run EngineとObservability

- Acquisition Runの状態機械、Queue Job、retry/backoffを実装する。
- 構造化log、metrics、tool audit、partial failureを実装する。
- Parser Versioningとreprocessを実装する。

### Milestone 4 — Agent SDKとDiscovery Mode

- `AcquisitionOrchestrator` portへAgent SDK adapterを接続する。
- Agentへ公開するToolをallowlist化する。
- budget、stop condition、domain scope、Profile Candidate出力schemaを実装する。
- Fake Agentで決定論的integration testを維持する。

### Milestone 5 — Monitoring、Health、Drift、Self-Healing Candidate

- ACTIVE ProfileによるMonitoringを実装する。
- baselineとquality validationを実装する。
- `PARSER_DRIFT`、Health状態遷移、再Discovery candidateを実装する。
- 承認なしでProfileが切り替わらないことをtestする。

### Milestone 6 — DARPA Vertical Slice

- DARPA公式サイトをDiscovery Modeで探索する。
- 発見したfeed/sitemap/index/document patternを証拠付きProfile Candidateにする。
- 承認済みfixtureまたはレビュー手順を経てACTIVE化する。
- HTML、XML/Feed、PDFを少なくとも各1例処理する。公式サイトで該当形式が存在しない場合は、その事実を記録し、fixtureでTool contractを検証する。
- Monitoringを2回実行し、2回目のidempotencyを確認する。
- RAW、Markdown、metadata、Revision、Healthを確認する。
- RAWからnetworkなしで再処理する。

### Milestone 7 — NEDO Architecture Test

- DARPA実装のSource固有classを複製しない。
- NEDOを新しいSource + Source ProfileとしてDiscoveryする。
- 日本語metadata、HTML/XML/PDFを共通Toolで処理する。
- 必要な差異はProfileで表現し、共通能力の不足だけを汎用Toolへ追加する。
- Source名による分岐がないことを静的レビューする。

### Milestone 8 — Phase 0 Hardening

- 全Acceptance Test、failure injection、backfill/reprocessを実行する。
- 運用runbook、Profile承認・rollback手順、既知制約を文書化する。
- Definition of Doneを証拠付きで確認する。

---

## 16. Acceptance Tests

テストは外部サイトの瞬間的な状態だけに依存させず、保存fixtureによる再現可能なtestと、明示的に分離したlive smoke testを用意する。

### AT-01 Discovery

DARPAのseed URLを与えると、許可scope内で公式feed/sitemap/index/PDF候補を発見し、根拠と検証結果を含むSource Profile Candidateを保存する。

### AT-02 Profile Approval Safety

`PENDING_APPROVAL`のProfileはMonitoringに使用されない。承認操作でのみACTIVEになり、旧versionと監査履歴が残る。

### AT-03 RAW Fidelity

HTML/XML/PDFの取得byte列と保存後に読み戻したbyte列のSHA-256が一致する。RAWは更新APIを持たない。

### AT-04 Normalize

各media typeから共通Document ModelとMarkdownが生成され、RAW ID、Parser version、Source、URL、時刻へ追跡できる。

### AT-05 Idempotent Monitoring

同じfixture/profileでMonitoringを2回実行しても、同一content hashに対するDocument RevisionとRAW Blobが不必要に増えない。fetch observationは実行事実として記録できる。

### AT-06 Revision

同一stable keyの本文を変更するとRevision番号が増え、旧RAWと旧NORMALIZEDを参照可能なまま保持する。

### AT-07 Reprocessing

外部networkを遮断した状態で、保存済みRAWを新Parser versionにより再処理できる。新旧NORMALIZEDが併存し、入力とversionを追跡できる。

### AT-08 Silent Failure

HTTP 200の空本文、challenge page、必須field欠落、feed件数急減fixtureを入力すると、正常成功ではなくquality failureまたは`PARSER_DRIFT`になる。

### AT-09 HTTP Safety

timeout、429、5xx、redirect loop、許可host外redirect、oversized body、content-type mismatchが共通errorとなり、retry policyと監査記録が期待どおりになる。

### AT-10 XML Security

外部entityを含むXMLを与えても外部参照せず、安全に失敗する。不正XMLを0件の正常feedと扱わない。

### AT-11 PDF Failure Semantics

text PDFはMarkdown化され、scan-only/encrypted/corrupt PDFは理由付きの非正常状態になる。RAWはどの場合も取得成功分を保持する。

### AT-12 Health

正常fixtureでHEALTHY、品質低下でDEGRADED/PARSER_DRIFT、承認済みProfileによるcanary成功でHEALTHYへ戻る。すべての遷移にevidenceがある。

### AT-13 Self-Healing Safety

drift後の再Discoveryが新Profile Candidateを作っても、承認前はACTIVE Profileが変わらない。rollbackで前versionへ戻せる。

### AT-14 Agent Boundary

Agentが使える操作は登録Toolだけで、DB/Storageへの直接書込みや許可host外fetchはできない。Tool request/responseはschema validationされる。

### AT-15 Architecture Test — NEDO

NEDOを追加する際、DARPA専用Crawler/Serviceのcopy、Source名分岐、NEDO専用pipelineを追加せず、Source登録とProfileでVertical Sliceが動く。必要な共通改善はDARPA testを壊さない。

### AT-16 End-to-End

DARPAとNEDOについて、Discovery → Profile approval → Monitoring → Fetch → RAW → Parse → Normalize → Storage → Health → Reprocessが一本につながり、run IDから全artifactと判断履歴を追跡できる。

---

## 17. Definition of Done

Phase 0は、次のすべてが満たされた場合にだけ完了とする。

- Laravel + PostgreSQL上で全migrationが適用できる。
- Agent SDKがオーケストレーターとして接続され、Agent/Tool境界が守られている。
- 7つのTool契約が実装・testされている。
- Discovery ModeとMonitoring Modeが別runとして動作する。
- Source Profileがversion管理され、承認・rollbackできる。
- RAW / NORMALIZED / DERIVEDの境界とprovenanceが実装されている。
- RAW HTML/XML/PDFが改変されず保存される。
- Parser Versioningとnetwork不要の再処理が動作する。
- Document identity、deduplication、RevisionがAcceptance Testを通る。
- Silent Failureと`PARSER_DRIFT`がfixtureで検出される。
- Source Healthが計測・保存され、理由付きで状態遷移する。
- Self-Healingが安全なProfile Candidate生成まで動作し、無承認更新をしない。
- DARPA Vertical Sliceがend-to-endで完了する。
- NEDOがPlatformへのSource追加としてend-to-endで完了する。
- NEDO追加にDARPA固有コードのcopyやSource名分岐がない。
- 全自動testとAcceptance Testが成功し、live smoke testはfixture testと分離されている。
- setup、運用、再処理、承認、rollback、障害対応の手順が文書化されている。
- 記事生成、評価、一般公開UIが実装されていない。

「一部URLを取得できた」「Markdownが1件できた」「Agentが探索結果を文章で報告した」だけでは完了ではない。

---

## 18. 実装品質と運用上の要件

- Queue Jobはidempotentにする。
- transaction境界を明示し、Blob保存とDB保存の部分失敗を回収可能にする。
- retry対象と非retry対象をerror codeで区別する。
- secret、response body、個人情報を通常logへ出さない。
- Agent prompt、model、tool schema、Profile、Parserのversionをrunへ記録する。
- 時刻はUTCで保存し、表示時だけtimezoneを適用する。
- URL、media type、文字encoding、言語の不正値をvalidationする。
- 大きな処理はQueueへ送り、CLI/request timeoutへ依存しない。
- 外部networkを使うtestには明示的なtagを付け、通常CIはfixtureで安定させる。
- 依存libraryを薄いinterfaceで包み、Parserの交換とversion併存を可能にする。
- 取得件数を品質の代理にしない。provenance、完全性、再現性を優先する。

---

## 19. 推奨ディレクトリ境界

既存規約がある場合はそちらを優先する。新規設計時の例は次のとおり。

```text
app/
  Acquisition/
    Agent/
    Application/
    Domain/
    Tools/
      Discovery/
      Http/
      Html/
      Xml/
      Pdf/
      Normalize/
      Storage/
    Infrastructure/
      AgentSdk/
      Persistence/
      BlobStorage/
    Health/
    Reprocessing/
database/migrations/
tests/
  Unit/Acquisition/
  Feature/Acquisition/
  Integration/Acquisition/
  Acceptance/Acquisition/
  Fixtures/Acquisition/
docs/
  adr/
  acquisition/
```

これは責務境界を示す例であり、名前を守ること自体が目的ではない。重要なのは、Source固有情報がPlatformの共通実装へ漏れないことである。

---

## 20. 最終成果物

Claude CodeはPhase 0完了時に次を提出する。

1. 実装コードとmigration
2. 自動testと保存fixture
3. DARPA/NEDOのSource Profileとversion履歴
4. DARPA/NEDOのend-to-end実行証跡
5. RAW、NORMALIZED Markdown、Revision、Healthのサンプル参照
6. Acceptance Test結果
7. ADR、データモデル、Tool contract、runbook
8. 既知の制約とPhase 1へ送る課題

Phase 1の提案は別文書に分ける。Phase 0の実装中にPhase 1の機能を先行実装しない。

---

## 21. 開始時チェックリスト

- [ ] リポジトリと既存規約を調査した
- [ ] Agent SDKを確認した。未確定ならportとFakeで分離した
- [ ] Phase 0外の機能を実装対象から除外した
- [ ] Source Profile schemaを確定した
- [ ] RAWのimmutable方針とBlob Storeを確定した
- [ ] identity / dedup / Revision規則を確定した
- [ ] Tool DTOとerror taxonomyを確定した
- [ ] quality baselineと`PARSER_DRIFT`条件を確定した
- [ ] fixtureとlive smoke testを分離した
- [ ] DARPA Vertical Sliceの対象document typeを記録した
- [ ] NEDO Architecture Testを最終Gateとして登録した

このチェックリストを満たした後、Milestone 1から順に実装を開始すること。
