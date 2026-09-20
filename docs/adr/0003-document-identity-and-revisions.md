# ADR-0003: Document Identity、重複排除、Revision

- 日付: 2026-09-21
- 状態: 承認済み（1 件の既知リスクあり、下記「未解決」）
- 対応する計画書の節: §7.7、§10、AT-05、AT-06

## 背景

URL だけを identity にしない、という要件がある。Feed GUID、公式 ID、canonical URL、安定 URL パターンを優先順位付きで使い、Source 内で一意な `stable_key` を作る。

## 決定

### stable_key の導出順序

上から順に試し、最初に得られたものを使う。どの規則で決まったかを `documents.identity_rule` に記録する。

| 優先 | 規則 | stable_key の形 |
|---|---|---|
| 1 | Feed entry の GUID / Atom `id` / Sitemap 以外の公式 ID（Profile の `document_patterns[].identity` で指定） | `guid:<値>` |
| 2 | HTML の `<link rel="canonical">` または `og:url` を正規化したもの | `url:<正規化 URL>` |
| 3 | HTTP の最終 URL（redirect 後）を正規化したもの | `url:<正規化 URL>` |

`documents` は `(source_id, stable_key)` で一意。

### URL 正規化

- scheme と host を小文字化、既定ポート（80/443）を除去。
- fragment（`#...`）を除去。
- 追跡パラメータを除去: `utm_*`、`fbclid`、`gclid`、`mc_cid`、`mc_eid`、`ref`、`source`。この一覧は 1 か所の定数に置き、Profile の `crawl_policy.strip_query_params` で追加できる。
- 残った query をキー順に並べ替える。意味のある query（`?id=123` など）は消さない。
- 末尾スラッシュは、パスが空でない限り除去する（`/news/` → `/news`、`/` はそのまま）。
- パーセントエンコードは RFC 3986 の非予約文字を復号し、それ以外は大文字に統一する。

### Revision の規則（計画書 §10 をそのまま採用）

- 初見の stable_key: `documents` 行と `document_revisions` の revision_no = 1 を作る。
- 同一 stable_key・同一 content_hash: Revision を作らない。`fetch_observations` に取得事実を記録し、`documents.last_seen_at` を更新する。
- 同一 stable_key・異なる content_hash: revision_no を +1 して追加する。旧 Revision と旧 RAW は残す。
- 異なる URL が同一 canonical / GUID を示す: 同一 Document として扱い、`document_aliases`（URL → document_id）に根拠付きで記録する。
- identity が曖昧（例: canonical が別 host を指す、GUID と canonical が別 Document を指す）: 自動 merge せず、`source_events` に `IDENTITY_CONFLICT` を記録して Document は最終 URL 規則で作る。

### content_hash

`content_hash` は **RAW body の SHA-256** とする。`raw_artifacts.sha256` と同じ値であり、`BlobStore` のコンテンツアドレスとも一致する（ADR-0002）。

### Storage Tool の idempotency

`append_document_revision` は `(document_id, content_hash)` の一意制約で保護する。同じ入力を 2 回渡しても 2 つ目は既存 Revision を返す（AT-05）。`fetch_observations` は制約なしで毎回 insert する。

## 却下した案

- **URL をそのまま identity にする**: 追跡パラメータや末尾スラッシュ差で同じ Document が複数になる。
- **本文の類似度で同一判定する**: 判定が確率的になり、監査で説明できない。

## 未解決（既知リスク）

**動的 HTML による Revision の増殖。** RAW hash を content_hash にすると、CSRF トークンや「最終更新: 今日」のような本文外の変化でも新 Revision が作られる。
Phase 0 は計画書どおり RAW hash で進め、Milestone 6 の DARPA Monitoring 2 回目（AT-05）で実測する。増殖が確認された場合の対処案は、Profile に `revision_hash_source: raw | normalized_body` を追加し、NORMALIZED の本文 Markdown の hash を Revision 判定に使えるようにすること。RAW の保存規則（同一 byte 列は 1 つ）は変えない。
