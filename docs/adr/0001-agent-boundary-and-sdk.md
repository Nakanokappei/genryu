# ADR-0001: Agent 境界と Claude Agent SDK サイドカー

- 日付: 2026-09-21
- 状態: 承認済み（2026-09-21、Agent SDK をサイドカーワーカーとして採用する決定を受けて）
- 対応する計画書の節: §3.1、§4、§7、§15 Milestone 0 / 4、AT-14

## 背景

計画書は「Agent は判断し、Tool は実行する」を要求し、Agent が DB や Blob Storage に直接触れることを禁じている。
Agent SDK の製品は計画書時点で未確定だったため、`AcquisitionOrchestrator` をポートとして分離し、決定論的 Fake でプラットフォームをテスト可能にすることが求められていた。

Milestone 0 の調査結果:

- 隣接 4 プロジェクト（ccar-f、voc-triage、anki365、ConversationalBI）に Agent SDK の前例はない。voc-triage は Python ワーカーで OpenAI SDK を使う。
- voc-triage の Laravel ↔ ワーカー接続は、`Process` ファサード（配列形式、同期 `run()`）、cwd=`worker/`、`worker/.env` に秘密情報、一時 JSON ファイルで入出力、という一つの型で統一されている。
- Claude Agent SDK は Python (`claude-agent-sdk`) と TypeScript (`@anthropic-ai/claude-agent-sdk`) のみ。カスタムツールはプロセス内 MCP サーバーとして登録し、`tools=[]` で組み込みツールを全て外せる。Python の `@tool` は `content` と `is_error` だけを返し、`structuredContent` は使えない。認証は API キー（claude.ai ログインは不可）。
- ローカル環境: Python 3.13.3、uv 0.8.8、Claude Code CLI 2.1.239。

## 決定

### 1. Agent は Claude Agent SDK（Python）のサイドカーワーカーで動かす

- 配置は `worker/`（voc-triage と同じ）。パッケージングは uv（`pyproject.toml` + `uv.lock`）。voc-triage の requirements.txt のみ・ロックなしという負債は引き継がない。
- Laravel は Queue Job の中から `Process::path(base_path('worker'))->timeout(...)->run([python, '-m', 'acquisition_agent', '--input', ..., '--output', ...])` で起動する。配列形式、同期実行、Queue の timeout はプロセス timeout より長くする。
- 入力は run ID・モード・Source・Profile・budget を含む JSON、出力は run の結果サマリ（終了理由、tool 呼び出し数、SDK が返す usage / cost）を含む JSON。本文データは JSON に載せず、常に Storage Tool 経由で保存する。
- 秘密情報（`ANTHROPIC_API_KEY`）は `worker/.env` のみに置く。Laravel の `.env` とは共有しない。モデル名は `TW_AGENT_MODEL`（既定 `claude-opus-5`）で環境変数から与える。

### 2. Tool は Laravel 側に実装し、ワーカーは薄いプロキシだけを持つ

- 7 つの Tool（Discovery、HTTP Fetch、HTML、XML/Feed、PDF、Normalize、Storage）は PHP で実装する。DTO、schema validation、監査ログ、DB トランザクションはすべて Laravel 側にある。
- ワーカーの MCP サーバー `acquisition` は、Tool ごとに 1 ハンドラを持つ。各ハンドラは `php artisan acquisition:tool <tool_name> --run=<run_id> --request=<json path> --response=<json path>` を子プロセスで呼び、返ってきた JSON をそのまま `content` のテキストとして Claude に返す。ハンドラ自身はネットワークにも DB にも触れない。
- Agent SDK には `tools=[]`（組み込みツール全削除）と `allowed_tools=["mcp__acquisition__*"]` を渡す。これにより Agent が使える操作は登録 Tool だけになる（AT-14）。
- Tool 呼び出しごとに `tool_invocations` 行を Laravel 側で記録する。ワーカー側では記録しない。記録責務を一か所にするため。

### 3. `AcquisitionOrchestrator` はポートとして PHP に置く

```text
app/Acquisition/Agent/AcquisitionOrchestrator.php      (interface)
app/Acquisition/Infrastructure/AgentSdk/ClaudeAgentSdkOrchestrator.php  (Process でワーカー起動)
app/Acquisition/Infrastructure/AgentSdk/FakeOrchestrator.php            (決定論的スクリプト)
```

- `FakeOrchestrator` は「この順で Tool をこの引数で呼ぶ」というスクリプトを受け取り、本番と同じ Tool ディスパッチャを通して実行する。LLM もネットワークも使わずに Run Engine・監査・Health を統合テストできる。
- テストでは `Process::fake()` ではなく `FakeOrchestrator` を束縛する。ワーカー起動の配線そのものは、`Process::fake()` を使う小さな契約テスト 1 本で確認する。

## 却下した案

- **Tool を Python 側に実装する**: Agent と Tool が同じプロセスに同居し、「Agent が DB に触れない」保証がコード規約だけになる。永続化と監査が二重化する。
- **Laravel から Claude API の tool-use ループを直接組む**: 実現可能だが、探索の計画・停止判断・サブタスク分割を自前で書くことになる。Agent SDK はこれを持っている。
- **TypeScript ワーカー**: 技術的には同等。隣接プロジェクトの前例（Python）と、開発者の既存ツールチェーンに合わせて Python を選んだ。差し替えは `worker/` の中に閉じる。
- **ワーカーから Laravel を HTTP（localhost）で呼ぶ**: 1 呼び出しあたりの Laravel 起動コスト（数百 ms）を避けられるが、認証トークンとネットワーク面が増える。Phase 0 の Discovery は budget で数百呼び出し以下に抑えるため、CLI ブリッジで十分と判断。latency が問題になれば HTTP ブリッジに置き換える。この判断は Milestone 4 で実測して見直す。

## 影響

- `worker/` は Laravel の Tool コマンドを呼ぶため、PHP と Python が同じファイルシステムと同じ DB を見る前提になる（voc-triage の Dockerfile と同じ「同居」判断）。
- Agent の prompt、モデル名、tool schema の版は run ごとに `acquisition_runs` に記録する（計画書 §18）。
- Python 側の単体テストは MCP ハンドラの引数変換と `is_error` の組み立てだけを対象にする。Tool の意味論のテストはすべて PHP 側。

## 未解決

- 実機での Tool 呼び出し latency と Discovery 1 回あたりのコスト。Milestone 4 の最初の live smoke で計測する。
