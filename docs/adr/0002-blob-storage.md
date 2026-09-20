# ADR-0002: Blob Storage と RAW の不変性

- 日付: 2026-09-21
- 状態: 承認済み
- 対応する計画書の節: §3.4、§4、§9、AT-03

## 背景

RAW 本文（HTML / XML / PDF の byte 列）は最大 50 MB（Profile の `max_body_bytes` 既定値）になり得る。PostgreSQL に直接格納しない方針が計画書で決まっている。開発環境はローカルディスク、将来は S3 互換に差し替える必要がある。

voc-triage の教訓: S3 ディスクに対する `Storage::disk()->path()` は例外を投げず、存在しないキー文字列を返して静かに壊れる。パスで触るものとバイト列で触るものを分ける規則が必要。

## 決定

1. **専用ディスク `acquisition`** を `config/filesystems.php` に追加する。ローカルでは `driver: local`、`root: storage_path('app/acquisition')`。本番は `FILESYSTEM_ACQUISITION_DRIVER=s3` で差し替える。既定の `local` / `public` ディスクは使わない（`public` は認証なしで配信されるため）。
2. **ポート `BlobStore`** を `app/Acquisition/Infrastructure/BlobStorage/` に置く。公開メソッドは `put(string $bytes, string $mediaType): BlobRef`、`get(BlobRef): string`、`exists(BlobRef): bool` の 3 つだけ。`delete` と上書きを行うメソッドは持たない（AT-03「RAW は更新 API を持たない」）。
3. **コンテンツアドレス方式**。保存キーは `raw/<sha256 先頭 2 桁>/<次の 2 桁>/<sha256>`。同じ byte 列は同じキーになるため、重複排除は自動で成立する。`put` は既存キーがあれば書き込まず、読み戻して hash が一致することだけ確認する。
4. **NORMALIZED も同じ仕組み**で `normalized/<...>/<sha256>` に置く。Parser version が違えば出力 byte 列が違い、鍵も違うので併存できる。
5. **アプリコードは `->path()` を呼ばない**。読み書きは常に `get`/`put` の byte 列 API を通す。ローカルの一時ファイルが必要な処理（PDF 解析ライブラリがファイルパスを要求する場合など）は、`BlobStore` から読んだ byte 列を Laravel の一時ディレクトリに書き、処理後に消す。
6. **`raw_artifacts` 行と Blob 書き込みの順序**は「Blob を先に put、成功後に DB 行を insert」。Blob があって行がない状態は孤児として無害（同じ内容の次回 put で再利用される）。行があって Blob がない状態は作らない。

## 却下した案

- **PostgreSQL の bytea / Large Object**: 50 MB の PDF が数千件になると DB のバックアップと VACUUM が重くなる。計画書も明示的に避けている。
- **日付ベースのキー（`2026/09/21/<uuid>`）**: 重複排除を別途実装する必要がある。コンテンツアドレスなら不要。
- **ファイル名に元 URL を含める**: 長さと文字種の制約、S3 のキー制約に引っかかる。URL は `fetch_observations` / `raw_artifacts` のカラムに持つ。

## 影響

- `BlobRef` は `{uri, sha256, bytes, media_type}` の値オブジェクト。`uri` は `acquisition://raw/ab/cd/<sha256>` のようにディスク名を含み、DB の `blob_uri` カラムにそのまま入る。
- ローカルの `storage/app/acquisition/` は `.gitignore` 対象。fixture 由来の RAW はテスト内で生成し、コミットしない。
