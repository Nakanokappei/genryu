# ADR-0005: Source Profile Schema v1

- 日付: 2026-09-21
- 状態: 承認済み
- 対応する計画書の節: §3.7、§6、§14、AT-02、AT-13

## 背景

Source 固有の知識はコードではなく version 付きの Source Profile（JSON）に置く。Profile は上書きせず version を追加し、1 Source につき ACTIVE は同時に 1 つ。Agent は候補を作るだけで、承認前に ACTIVE を変えない。

## 決定

### 保存場所と検証

- JSON Schema（draft 2020-12）を `resources/schemas/source_profile.v1.schema.json` に置く。`schema_version` が上がったら別ファイルを追加し、旧ファイルは残す。
- 検証ライブラリは `opis/json-schema`（draft 2020-12 対応、依存が少ない）。Storage Tool の `store_source_profile_candidate` と、承認コマンドの両方で検証する。検証に通らない Profile は保存しない。
- 実体は `source_profiles.profile_json`（jsonb）。運用で検索する field はカラムにも持つ: `source_id`、`version`、`schema_version`、`status`、`created_by_run_id`、`approved_at`、`approved_by`、`superseded_at`。
- `(source_id, version)` に一意制約。`status = 'ACTIVE'` の行が Source ごとに最大 1 つであることは部分一意 index（`UNIQUE (source_id) WHERE status = 'ACTIVE'`）で DB が保証する。

### status の遷移

```text
PENDING_APPROVAL ──approve──▶ ACTIVE ──(新 version が approve)──▶ SUPERSEDED
       │                        │
       └──reject──▶ REJECTED    └──rollback──▶ SUPERSEDED（直前の SUPERSEDED を ACTIVE に戻す）
```

- `approve` と `rollback` は Artisan コマンド（`acquisition:profile approve|rollback`）でのみ行う。Agent と Storage Tool には ACTIVE を変える API がない。
- すべての遷移を `source_events` に `PROFILE_ACTIVATED` / `PROFILE_ROLLED_BACK` / `PROFILE_REJECTED` として記録する。

### Schema v1 の field

計画書 §6 の JSON を基礎に、次を確定する。

| field | 型 | 必須 | 備考 |
|---|---|---|---|
| `schema_version` | const 1 | yes | |
| `source_key` | string (`^[a-z0-9_-]+$`) | yes | `sources.key` と一致 |
| `display_name` | string | yes | |
| `profile_version` | integer ≥ 1 | yes | カラム `version` と一致 |
| `status` | enum | yes | 上記 4 値 |
| `base_url` | uri | yes | |
| `allowed_hosts` | string[] (1 件以上) | yes | redirect 後も検証 |
| `entrypoints[]` | `{type, url, priority}` | yes (1 件以上) | `type`: `rss` / `atom` / `sitemap` / `sitemap_index` / `index` / `api` |
| `document_patterns[]` | `{include, exclude?, document_type, identity?}` | yes | `include`/`exclude` は URL の正規表現。`identity` は `{"from": "feed_guid" \| "canonical" \| "url"}` |
| `content_types` | string[] | yes | |
| `parser_bindings` | map media type → `<kind>.<name>@<version>` | yes | 例 `text/html` → `html.generic@1` |
| `quality_expectations` | `{required_fields, minimum_text_characters, minimum_item_ratio_to_baseline}` | yes | `required_fields` は Normalize が出す field 名の enum（`canonical_url` / `title` / `published_at` / `updated_at` / `language` / `body`）。存在しない名前を要求すると全文書が不合格になるため検証で弾く（2026-09-21、NEDO 候補で発生） |
| `crawl_policy` | `{max_depth, max_urls_per_run, requests_per_minute, timeout_seconds, max_body_bytes, strip_query_params?}` | yes | |
| `evidence` | object | no | Discovery が候補を選んだ根拠（robots / sitemap の URL、サンプル URL、backtest の件数） |
| `discovered_at`, `last_verified_at`, `approved_at`, `approved_by` | date-time / string / null | 一部 | |

`additionalProperties: false`。未知の field は保存時に拒否する。

### Parser ID の形式

`<kind>.<name>@<version>`。`kind` は `html` / `xml` / `pdf` / `normalize`。正規表現 `^(html|xml|pdf|normalize)\.[a-z0-9_]+@[0-9]+$`。Parser の実装は PHP の registry で ID から解決し、未登録 ID を持つ Profile は検証で落とす。

## 却下した案

- **Profile を PHP の設定ファイルや class にする**: Agent が生成できず、version 管理と承認が DB の外に出る。
- **`status` を profile_json の中だけに持つ**: 部分一意 index が張れず、「ACTIVE は 1 つ」を DB で保証できない。

## 影響

- Milestone 1 の migration は上記カラムと部分一意 index を含む。
- DARPA / NEDO の Profile は Discovery が生成したものを承認する。手書きの Profile を初期データとして入れる場合も同じ schema と承認コマンドを通す。
