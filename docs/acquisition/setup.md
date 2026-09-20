# ローカル実行と CI の前提（Milestone 0 時点）

最終更新: 2026-09-21

## ローカル環境

| 項目 | 内容 |
|---|---|
| PHP / Composer | Herd の PHP 8.5.8、Composer 2.10 |
| Laravel | 13、Livewire 4 + Flux、Fortify、Pest 5、Pint、PHPStan |
| DB | Homebrew PostgreSQL（5432）、database `technologywatch`、ユーザーはローカルユーザー、パスワードなし |
| Queue / Cache / Session | すべて `database` ドライバ（`.env` 既定） |
| URL | http://technologywatch.test（`herd link` 済み）。`.claude/launch.json` に `artisan serve --port=8036` もある |
| Python | 3.13.3（`/Library/Frameworks/Python.framework`）、uv 0.8.8 |
| Claude Code CLI | 2.1.239（Agent SDK の動作確認に使う。SDK 自体は `worker/` の venv に入れる） |
| PHP 拡張 | curl、dom、libxml、xml、mbstring、intl、fileinfo、gd、imagick、pdo_pgsql |
| PDF CLI | `pdftotext` / `qpdf` / `mutool` は未インストール（下記「未確定」参照） |

### 初回セットアップ

```bash
composer install
cp .env.example .env && php artisan key:generate
createdb technologywatch
createdb technologywatch_test   # used by phpunit.xml; RefreshDatabase migrates it
php artisan migrate
npm install && npm run build
```

ワーカー（Milestone 4 以降）:

```bash
cd worker
uv sync
cp .env.example .env   # ANTHROPIC_API_KEY を記入
```

### 検査

```bash
composer test          # Pint --test、PHPStan（--memory-limit=1G）、Pest
php artisan test       # Pest のみ
```

Herd の CLI PHP は `memory_limit=128M` で php.ini を読まないため、PHPStan は composer スクリプト側で `--memory-limit=1G` を渡している。

## テストの区分

| 区分 | 場所 | ネットワーク | 既定の実行 |
|---|---|---|---|
| Unit | `tests/Unit/Acquisition/` | なし | する |
| Feature | `tests/Feature/Acquisition/` | なし（`Http::fake()`、`FakeOrchestrator`） | する |
| Integration | `tests/Integration/Acquisition/` | なし（fixture の RAW を `BlobStore` に投入） | する |
| Acceptance | `tests/Acceptance/Acquisition/` | なし | する |
| Live smoke | 上記のうち `->group('live')` を付けたもの | あり（実サイト、実 LLM） | しない。`php artisan test --group=live` で明示実行 |
| Python | `worker/tests/` | なし（MCP ハンドラの引数変換のみ） | `uv run pytest` |

`phpunit.xml` の `<groups><exclude>` に `live` を入れる。live smoke は Milestone 6 以降に追加する。

## fixture

- `tests/Fixtures/Acquisition/<source_key>/` に HTML / XML / PDF の保存レスポンスを置く。1 ファイル = 1 HTTP レスポンス（body と主要 header を別ファイル、または 1 つの JSON）。形式は Milestone 1 で確定する。
- 実サイトから取った fixture は取得日時と URL を隣の `manifest.json` に記録する。
- PII を含む fixture は作らない（一次資料は公開情報のみ）。

## CI（GitHub Actions、未作成）

Milestone 3 までに次を用意する。

- `tests.yml`: PHP 8.5、PostgreSQL service（`postgres:17`）、`composer test`。Pest の DB は `phpunit.xml` の sqlite in-memory 既定ではなく **PostgreSQL** を使う（部分一意 index など Postgres 固有の制約をテストで確認するため）。
- 同じワークフローに Python のジョブ: `uv sync`、`uv run ruff check`、`uv run pytest`。voc-triage では Python 側が CI に入っておらず、契約のずれが本番まで届いた。ここでは最初から入れる。
- live smoke は CI で走らせない。

## 未確定（実装を左右するもの）

1. **Revision 判定の hash 源。** ADR-0003 の「未解決」。Milestone 6 で実測。

PDF テキスト抽出は `smalot/pdfparser`（純 PHP、システム依存なし）で `pdf.text@1` として実装した（2026-09-21、優先度低の判断に沿って最小構成）。レイアウト復元は弱く、OCR はない。DARPA の実 PDF で品質不足が出たら poppler ベースの実装を `pdf.text@2` として追加し、旧版と併存させる。

`ANTHROPIC_API_KEY` は用意済み（2026-09-21）。Milestone 4 で `worker/.env` に置く。それまでは `FakeOrchestrator` で進む。
