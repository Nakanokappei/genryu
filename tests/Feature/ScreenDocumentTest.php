<?php

use App\Actions\ProposeDecision;
use App\Actions\ProposeMaterial;
use App\Jobs\ExtractMaterial;
use App\Jobs\ScreenDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Screening;
use App\Models\ScreeningPrompt;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const SCREENING_PROMPT = 'あなたは Editorial Screening Gate です。ADOPT / REJECT / REVIEW で判定してください。';

/**
 * What the agent would answer, as the Responses API wire format: the
 * decision as the output text of a message, and the usage with the
 * cached and cache-written tokens apart.
 */
function screeningAnswer(array $decision, array $usage = []): array
{
    return [
        'output' => [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($decision, JSON_UNESCAPED_UNICODE)]]],
        ],
        'usage' => [
            'input_tokens' => $usage['input'] ?? 1200,
            'input_tokens_details' => ['cached_tokens' => $usage['cached'] ?? 1000, 'cache_write_tokens' => $usage['write'] ?? 0],
            'output_tokens' => $usage['output'] ?? 80,
        ],
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4.1']);
    EditorialPolicy::query()->create(['layer' => 'content_filtering', 'body' => SCREENING_PROMPT, 'model' => 'gpt-5.6-terra']);
    $this->actingAs(User::factory()->create());
});

function screenDocument(Document $document, ?string $model = null): Screening
{
    Queue::fake();
    $screening = ScreenDocument::queueFor($document, $model);
    (new ScreenDocument($screening))->handle(app(ProposeDecision::class));

    return $screening->refresh();
}

// The request: the fixed prompt as the developer message with the explicit cache breakpoint, the document after it, the answer constrained to the decision's JSON; the run keeps the decision, the usage and the prompt version.
it('screens a fetched document with the prompt cached and keeps the decision, the tokens and the prompt version', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(screeningAnswer(
        ['decision' => 'ADOPT', 'primary_reason' => 'DEMONSTRATION', 'evidence' => '実証実験を開始した。', 'reason' => '研究室から実環境へ移った。'],
        ['input' => 3000, 'cached' => 2500, 'write' => 0, 'output' => 90],
    ))]);
    $document = Document::factory()->fetched()->create(['markdown' => "# 実証実験\n\n実証実験を開始した。"]);

    $screening = screenDocument($document);

    expect($screening)->toMatchArray(['status' => 'screened', 'decision' => 'adopt', 'primary_reason' => 'DEMONSTRATION', 'evidence' => '実証実験を開始した。', 'model' => 'gpt-5.6-terra', 'input_tokens' => 3000, 'cached_tokens' => 2500, 'cache_write_tokens' => 0, 'output_tokens' => 90])
        ->and($screening->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($screening->prompt->version)->toBe(1)->and($screening->prompt->text)->toBe(SCREENING_PROMPT)
        ->and($document->refresh()->screening?->is($screening))->toBeTrue()
        ->and($document->isRejected())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $developer = $body['input'][0];
        $user = $body['input'][1];

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body['model'] === 'gpt-5.6-terra'
            && $body['prompt_cache_options'] === ['mode' => 'explicit']
            && ! array_key_exists('instructions', $body)
            // The fixed prompt first, with the breakpoint on it; nothing of the document before the breakpoint.
            && $developer['role'] === 'developer'
            && $developer['content'][0]['type'] === 'input_text'
            && $developer['content'][0]['text'] === SCREENING_PROMPT
            && $developer['content'][0]['prompt_cache_breakpoint'] === ['mode' => 'explicit']
            && $user['role'] === 'user'
            && str_contains($user['content'], '実証実験を開始した。')
            && $body['text']['format']['type'] === 'json_schema'
            && $body['text']['format']['strict'] === true
            && $body['text']['format']['schema']['properties']['decision']['enum'] === ['ADOPT', 'REJECT', 'REVIEW'];
    });
});

// The cost comes from the prices per million tokens configured for the model: cached input at the cached price.
it('estimates the cost of a screening from the configured prices', function () {
    config(['services.openai.prices.gpt-5.6-terra' => ['input' => 2.0, 'cached' => 0.5, 'output' => 8.0]]);

    expect(ScreenDocument::estimatedCost('gpt-5.6-terra', ['input_tokens' => 1_000_000, 'cached_tokens' => 500_000, 'output_tokens' => 100_000]))
        ->toBe(['estimated_input_cost' => 1.25, 'estimated_output_cost' => 0.8, 'estimated_total_cost' => 2.05])
        ->and(ScreenDocument::estimatedCost('gpt-6-astra', ['input_tokens' => 10, 'cached_tokens' => 0, 'output_tokens' => 1]))
        ->toBe(['estimated_input_cost' => null, 'estimated_output_cost' => null, 'estimated_total_cost' => null]);
});

// A changed prompt is a new version; an unchanged one is not; each screening pins the version it ran with.
it('numbers the prompt versions as the prompt changes', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(screeningAnswer(['decision' => 'REJECT', 'primary_reason' => 'EVENT_PR', 'evidence' => '', 'reason' => '']))]);
    $first = screenDocument(Document::factory()->fetched()->create());
    $second = screenDocument(Document::factory()->fetched()->create());

    Livewire::test('pages::documents.index')->set('contentFiltering', SCREENING_PROMPT.' 改訂。')->call('saveContentFiltering')->assertHasNoErrors();
    $third = screenDocument(Document::factory()->fetched()->create());

    expect($first->prompt->version)->toBe(1)->and($second->prompt->id)->toBe($first->prompt->id)
        ->and($third->prompt->version)->toBe(2)->and($third->prompt->text)->toBe(SCREENING_PROMPT.' 改訂。')
        ->and(ScreeningPrompt::query()->count())->toBe(2);
});

// A document the title filter excluded, or not fetched, or an answer without a decision: the run fails with the reason and the document is not stopped.
it('records a failed screening instead of throwing', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(['output' => [], 'usage' => []])]);

    expect(screenDocument(Document::factory()->fetched()->create(['excluded_by' => '寄稿; 掲載']))->status_message)->toBe('タイトルフィルタで対象外になった文書です。')
        ->and(screenDocument(Document::factory()->create())->status_message)->toBe('文書がまだ取得されていません。')
        ->and(screenDocument(Document::factory()->fetched()->create()))->toMatchArray(['status' => 'failed', 'status_message' => 'エージェントの回答に判定がありません。']);
});

// A rejected document is stopped at the gate: the material job refuses it; an adopted or reviewed one goes on.
it('keeps a rejected document from the material', function () {
    $rejected = Document::factory()->fetched()->create();
    Screening::factory()->for($rejected)->rejected()->create();

    $material = ExtractMaterial::queueFor($rejected);
    (new ExtractMaterial($material))->handle(app(ProposeMaterial::class));

    expect($material->refresh())->toMatchArray(['status' => 'failed', 'status_message' => 'スクリーニングで不採用になった文書です。'])
        ->and($rejected->refresh()->isRejected())->toBeTrue();
    Http::assertNothingSent();
});

// The screens: the bulk button queues the fetched documents not screened yet; the list shows and filters by the decision; the detail shows the run and screens again with a chosen model.
it('queues screenings from the screens and shows the decisions', function () {
    Queue::fake();
    $prompt = ScreeningPrompt::factory()->create();
    $adopted = Document::factory()->fetched()->create(['title' => 'Adopted doc']);
    Screening::factory()->for($adopted)->for($prompt, 'prompt')->create(['reason' => '実環境での実証']);
    $reviewed = Document::factory()->fetched()->create(['title' => 'Reviewed doc']);
    Screening::factory()->for($reviewed)->for($prompt, 'prompt')->review()->create();
    $fresh = Document::factory()->fetched()->create(['title' => 'Fresh doc']);
    Document::factory()->fetched()->create(['title' => 'Excluded doc', 'excluded_by' => '発売']);
    Document::factory()->create(['title' => 'Unfetched doc']);

    Livewire::test('pages::documents.index')->call('screenDocuments');
    Queue::assertPushed(ScreenDocument::class, 1);
    expect($fresh->refresh()->screening)->toMatchArray(['status' => 'screening', 'model' => 'gpt-5.6-terra']);

    $titles = fn ($component) => $component->instance()->documents->pluck('title')->all();
    $component = Livewire::test('pages::documents.index')->assertSee('採用')->assertSee('要確認')->assertSee('判定中')->assertSee('実環境での実証');
    expect($titles($component->set('decision', 'adopt')))->toBe(['Adopted doc'])
        ->and($titles($component->set('decision', 'review')))->toBe(['Reviewed doc'])
        ->and($titles($component->set('decision', 'none')))->toBe(['Excluded doc', 'Unfetched doc']);

    // Review with a higher model from the document's screen.
    Livewire::test('pages::documents.show', ['document' => $reviewed->refresh()])->assertSet('screeningModel', 'gpt-5.6-terra')->assertSee('INSUFFICIENT_EVIDENCE')
        ->set('screeningModel', 'gpt-6-astra')->call('screen')->assertHasNoErrors();
    Queue::assertPushed(ScreenDocument::class, 2);
    expect($reviewed->refresh()->screening)->toMatchArray(['status' => 'screening', 'model' => 'gpt-6-astra'])
        ->and($reviewed->screenings()->count())->toBe(2);

    // The figures per prompt version on the list.
    $figures = Livewire::test('pages::documents.index')->assertSee('プロンプト版')->instance()->screeningFigures;
    expect($figures['versions'][0])->toMatchArray(['screened' => 2, 'adopted' => 1, 'rejected' => 0, 'reviewed' => 1])
        ->and(round($figures['versions'][0]['cache_hit_rate'], 3))->toBe(round(4000 / 6000, 3))
        ->and(collect($figures['reasons'])->pluck('count', 'primary_reason')->all())->toBe(['DEMONSTRATION' => 1, 'INSUFFICIENT_EVIDENCE' => 1]);
});

/*
 * The acceptance cases of the gate need the model itself; they run only
 * when SCREENING_ACCEPTANCE=1 with a real OPENAI_API_KEY in the
 * environment, and cost a few calls. The prompt is the default one.
 */
it('passes the acceptance cases with the real model', function (string $markdown, string $decision, ?string $reason) {
    Http::allowStrayRequests();
    config(['services.openai.key' => (string) env('OPENAI_API_KEY')]);
    EditorialPolicy::query()->where('layer', 'content_filtering')->update(['body' => EditorialPolicy::DEFAULTS['content_filtering']]);

    $screening = screenDocument(Document::factory()->fetched()->create(['markdown' => $markdown]), (string) env('SCREENING_ACCEPTANCE_MODEL', 'gpt-5.6-terra'));

    expect($screening->status)->toBe('screened', (string) $screening->status_message)
        ->and($screening->decision)->toBe($decision, $screening->primary_reason.' — '.$screening->reason);

    if ($reason !== null) {
        expect($screening->primary_reason)->toBe($reason, $screening->reason);
    }
})->with([
    'Case 1: 親子向け陶芸教室' => ["# 親子陶芸教室 開催のお知らせ\n\n2026年10月12日、当社研修センターにて親子向けの陶芸教室を開催します。定員20組、参加費無料。申込みはウェブサイトから。", 'reject', 'EVENT_PR'],
    'Case 2: 既存生成AIの社内導入' => ["# 生成AIを全社の業務に導入\n\n当社は、市販の生成AIサービスを全社員のメール作成と議事録作成に導入しました。作業時間の短縮が見込まれます。", 'reject', 'TECHNOLOGY_USE_ONLY'],
    'Case 3: 量子技術の従来不可能な実証' => ["# 室温で動作する量子メモリ、1秒超のコヒーレンス時間を初めて実証\n\n従来は極低温でしか実現できなかった量子メモリについて、室温で1.2秒のコヒーレンス時間を実証した。測定は独立した2つの装置で再現され、誤り率は0.3%であった。これにより冷却装置なしの量子中継器の設計が可能になる。", 'adopt', 'FRONTIER_BREAK'],
    'Case 4: DARPAのEngineering Challenge公募' => ["# DARPA、超小型原子時計チャレンジを公募\n\nDARPAは、体積10cm³以下、消費電力50mW以下、周波数安定度1e-11/日を満たす原子時計の試作を求めるプログラムを公募した。フェーズ1は18か月、各チームに最大500万ドルを提供する。", 'adopt', 'ENGINEERING_ATTACK'],
    'Case 5: 次世代太陽電池のPilot production line' => ["# ペロブスカイト太陽電池のパイロット生産ラインを建設\n\n当社は年産100MW規模のペロブスカイト太陽電池パイロット生産ラインを建設し、2027年に稼働を開始する。ロール・ツー・ロール方式で幅1mのフィルム基板に成膜し、量産時の歩留まり目標は90%。", 'adopt', 'INDUSTRIALIZATION'],
    'Case 6: 具体性のないビジョン発表' => ["# 量子技術は未来を変える\n\n当社CEOは記者会見で「量子技術はあらゆる産業を変革する。当社は量子時代のリーダーを目指す」と述べた。具体的な製品や計画は明らかにされなかった。", 'reject', 'OPINION_ONLY'],
    'Case 7: Engineeringへの移行が不明な科学研究' => ["# 新しい二次元材料で異常な熱伝導を観測\n\n研究チームは新しい二次元材料において、理論予測の3倍の熱伝導率を観測した。メカニズムは解明されておらず、試料はマイクロメートル規模で、応用に向けた検討は今後の課題としている。", 'review', null],
])->skip(env('SCREENING_ACCEPTANCE') !== '1', 'Runs against the real model only with SCREENING_ACCEPTANCE=1.');
