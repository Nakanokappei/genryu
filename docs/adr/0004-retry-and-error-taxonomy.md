# ADR-0004: Retry 方針と Error Taxonomy

- 日付: 2026-09-21
- 状態: 承認済み
- 対応する計画書の節: §7（Tool 契約）、§7.2、§13、§18、AT-09

## 背景

Tool の内部例外を生のまま Agent に返さず、再試行可否を含む共通 error に変換する必要がある。Queue Job は idempotent で、retry 対象と非 retry 対象を error code で区別する。Scheduler が同じ失敗を無限に再試行しないよう backoff / circuit breaker を設ける。

## 決定

### 共通 error DTO

すべての Tool は失敗時に次の形を返す。Agent（MCP ハンドラ）はこれを `is_error: true` のテキストとして Claude に渡す。

```json
{
  "error": {
    "code": "RATE_LIMITED",
    "message": "429 from www.darpa.mil; Retry-After 120s",
    "retryable": true,
    "retry_after_seconds": 120,
    "details": {"url": "...", "status": 429},
    "correlation_id": "...",
    "run_id": "..."
  }
}
```

PHP 側は `ToolError` 例外 1 つに `ErrorCode` enum を持たせる。Tool ディスパッチャがこれ以外の例外を捕まえた場合は `INTERNAL` に変換し、スタックトレースはログにだけ出す。

### Error code 一覧

| code | retryable | 発生源 |
|---|---|---|
| `TIMEOUT` | yes | HTTP Fetch |
| `RATE_LIMITED` | yes（Retry-After を尊重） | HTTP Fetch（429） |
| `SERVER_ERROR` | yes | HTTP Fetch（5xx） |
| `CONNECTION_FAILED` | yes | HTTP Fetch（DNS / TCP） |
| `TLS_ERROR` | no | HTTP Fetch |
| `CLIENT_ERROR` | no | HTTP Fetch（429 以外の 4xx） |
| `REDIRECT_LOOP` | no | HTTP Fetch（最大 redirect 超過） |
| `HOST_NOT_ALLOWED` | no | HTTP Fetch / Discovery（redirect 後も再検証） |
| `ROBOTS_DISALLOWED` | no | HTTP Fetch / Discovery |
| `BODY_TOO_LARGE` | no | HTTP Fetch（`max_body_bytes` 超過。途中で切断し、部分 body は保存しない） |
| `CONTENT_TYPE_MISMATCH` | no | HTTP Fetch（宣言と検出が不一致。RAW は保存し、警告として返す場合もある） |
| `INVALID_INPUT` | no | 全 Tool（request schema 違反） |
| `PARSE_FAILED` | no | HTML / XML / PDF |
| `XML_EXTERNAL_ENTITY_REJECTED` | no | XML（AT-10） |
| `UNSUPPORTED_SCANNED_PDF` | no | PDF（text layer なし） |
| `ENCRYPTED_PDF` | no | PDF |
| `QUALITY_FAILED` | no | Normalize / Validation（必須 field 欠落、本文短すぎ） |
| `BUDGET_EXHAUSTED` | no | Discovery（depth / URL 数 / wall time） |
| `INTERNAL` | no | 想定外の例外 |

`304 Not Modified` は error ではなく、Fetch の正常結果 `{"status": 304, "not_modified": true}` として返す。

### Retry の層

1. **HTTP Fetch Tool の内部**: retryable な code に対して最大 3 回、backoff は 1s → 4s → 16s に ±25% の jitter。`Retry-After` があればそれを優先し、上限 300 秒。上限を超える場合は retry せず `RATE_LIMITED` を返す。同じ URL への retry 回数は `fetch_observations.attempts` に記録する。
2. **Agent（Discovery Mode）**: Tool が `retryable: true` を返しても Agent 自身は同じ URL を再試行しない。別の経路を探すか、その URL を「取得失敗」として候補評価に反映する。プロンプトで明示する。
3. **Queue Job**: `tries = 1`。Job の再実行は人間または Scheduler の判断で新しい run として行う。voc-triage で採用された「Queue timeout はプロセス timeout より長くする」規則を踏襲する。
4. **Scheduler の circuit breaker**: Monitoring run が `FAILED` で終わった Source は、次の実行を 1 回スキップし、連続失敗ごとにスキップ数を倍にする（上限 24 時間）。連続 3 回で Source Health を `DEGRADED` にする。成功したらリセット。状態は `sources.consecutive_failures` と `sources.next_run_not_before` に持つ。

### 部分失敗

Run 全体は、Document 単位の失敗があっても続行する。各 Document の結果を `acquisition_runs.counters`（`fetched / new / revised / unchanged / failed / quality_failed`）に集計し、`failed > 0` の run は `COMPLETED_WITH_ERRORS` にする。`SUCCEEDED` は失敗ゼロのときだけ。drift 検知があれば `DEGRADED`（計画書 §12）。

## 却下した案

- **Laravel Queue の `tries` / `backoff` に retry を任せる**: Job 全体が再実行され、途中まで進んだ run の状態と混ざる。Tool 単位の retry のほうが監査しやすい。
- **例外クラスを Tool ごとに増やす**: Agent 側で解釈できない。code 1 つの enum のほうが JSON 境界を越えやすい。

## 影響

- `ErrorCode` enum は PHP に 1 つ。Python 側は文字列として扱い、解釈しない。
- ログには `code`、`correlation_id`、`run_id`、URL、status を出す。response body と headers の `Set-Cookie` / `Authorization` は出さない。
