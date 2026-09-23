<?php

use App\Actions\ProposeDecision;
use App\Actions\ProposeMaterial;
use App\Actions\ReviseDocumentSettings;
use App\Actions\ValidateMaterial;
use App\Jobs\ExtractMaterial;
use App\Jobs\ScreenDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\Models\Screening;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

function screenDocument(Document $document, ?string $model = null, int $pass = 1): Screening
{
    Queue::fake();
    $screening = ScreenDocument::queueFor($document, $model, $pass);
    (new ScreenDocument($screening))->handle(app(ProposeDecision::class), app(ReviseDocumentSettings::class));

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

// Every Markdown written to a document is kept as a revision; a screening pins the one it was queued on and reads that, whatever the document holds later.
it('keeps document revisions and screens the pinned one', function () {
    $document = Document::factory()->fetched()->create(['markdown' => "# v1\n\nFirst text."]);
    expect($document->revisions()->count())->toBe(1)->and($document->revisions()->first()?->chars)->toBe(mb_strlen("# v1\n\nFirst text."));

    $document->update(['markdown' => "# v1\n\nFirst text."]);
    $document->update(['status' => 'fetched']);
    expect($document->revisions()->count())->toBe(1);

    Queue::fake();
    $screening = ScreenDocument::queueFor($document);
    $document->update(['markdown' => "# v2\n\nSecond text."]);
    expect($document->revisions()->count())->toBe(2)->and($screening->revision?->markdown)->toBe("# v1\n\nFirst text.");

    Http::fake(['api.openai.com/v1/responses' => Http::response(screeningAnswer(['decision' => 'ADOPT', 'primary_reason' => 'DEMONSTRATION', 'evidence' => '', 'reason' => '']))]);
    (new ScreenDocument($screening))->handle(app(ProposeDecision::class), app(ReviseDocumentSettings::class));
    Http::assertSent(fn (Request $request): bool => str_contains($request->data()['input'][1]['content'], 'First text.') && ! str_contains($request->data()['input'][1]['content'], 'Second text.'));
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

    Livewire::test('pages::editorial.documents.index')->set('contentFiltering', SCREENING_PROMPT.' 改訂。')->call('saveContentFiltering')->assertHasNoErrors();
    $third = screenDocument(Document::factory()->fetched()->create());

    expect($first->prompt->version)->toBe(1)->and($second->prompt->id)->toBe($first->prompt->id)
        ->and($third->prompt->version)->toBe(2)->and($third->prompt->text)->toBe(SCREENING_PROMPT.' 改訂。')
        ->and(Prompt::query()->count())->toBe(2);
});

// A document the title filter excluded, or not fetched, or an answer without a decision: the run fails with the reason and the document is not stopped.
it('records a failed screening instead of throwing', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(['output' => [], 'usage' => []])]);

    expect(screenDocument(Document::factory()->fetched()->create(['excluded_by' => '寄稿; 掲載']))->status_message)->toBe('タイトルフィルタで対象外になった文書です。')
        ->and(screenDocument(Document::factory()->create())->status_message)->toBe('文書がまだ取得されていません。')
        ->and(screenDocument(Document::factory()->fetched()->create()))->toMatchArray(['status' => 'failed', 'status_message' => 'エージェントの回答に判定がありません。']);
});

// Nobody reviews by hand: a 要確認 from the first pass gets one second pass by the next model up, told so after the cached prompt and allowed only ADOPT or REJECT.
it('runs a second pass by the next model up when the first pass says review', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(screeningAnswer(['decision' => 'REVIEW', 'primary_reason' => 'INSUFFICIENT_EVIDENCE', 'evidence' => '', 'reason' => '本文だけでは判断できない。']))
        ->push(screeningAnswer(['decision' => 'ADOPT', 'primary_reason' => 'ENGINEERING_ATTACK', 'evidence' => '試作機の性能要件が示されている。', 'reason' => '具体的なEngineeringが始まった。']))]);
    $document = Document::factory()->fetched()->create(['markdown' => str_repeat('本文。', 400)]);

    $first = screenDocument($document, 'gpt-5.6-luna');
    expect($first->decision)->toBe('review')->and($first->pass)->toBe(1);
    Queue::assertPushed(ScreenDocument::class, fn (ScreenDocument $job): bool => $job->screening->pass === 2 && $job->screening->model === 'gpt-5.6-terra' && $job->screening->document->is($document));

    // (The queued second pass is faked above; here it is run by hand, as a third row in the document's history.)
    $second = screenDocument($document, 'gpt-5.6-terra', 2);
    expect($second)->toMatchArray(['decision' => 'adopt', 'pass' => 2, 'model' => 'gpt-5.6-terra'])
        ->and($document->refresh()->screening?->is($second))->toBeTrue()
        ->and($document->screenings()->count())->toBe(3);
    // The second pass never asks for a third.
    Queue::assertNotPushed(ScreenDocument::class, fn (ScreenDocument $job): bool => $job->screening->pass > 2);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        // The second pass: the cached prompt untouched, the instruction after it, REVIEW gone from the schema.
        return count($body['input']) === 3
            && isset($body['input'][0]['content'][0]['prompt_cache_breakpoint'])
            && $body['input'][1] === ['role' => 'developer', 'content' => ProposeDecision::SECOND_PASS]
            && $body['input'][2]['role'] === 'user'
            && $body['text']['format']['schema']['properties']['decision']['enum'] === ['ADOPT', 'REJECT'];
    });
});

// A reject of a document with a short body is suspect: the source's document settings are revised from its original, and the cured documents screened again.
it('revises the document settings and screens again when a short body is rejected', function () {
    Storage::fake('local');
    $page = '<html><body><header><h1>Ammonia burner programme</h1><p>'.str_repeat('A short teaser. ', 10).'</p></header>'
        .'<section class="body">'.str_repeat('<p>'.str_repeat('The long body of the release. ', 8).'</p>', 6).'</section></body></html>';
    $source = Source::factory()->create(['document_config' => ['content' => 'header', 'date' => '', 'remove' => '', 'fixed_text' => '']]);
    $short = Document::factory()->fetched()->for($source)->create(['title' => 'Ammonia burner programme', 'original_path' => "documents/{$source->id}/1.html", 'markdown' => '# Ammonia burner programme'.str_repeat("\n\nA short teaser.", 10)]);
    $other = Document::factory()->fetched()->for($source)->create(['title' => 'Another release', 'original_path' => "documents/{$source->id}/2.html", 'markdown' => '# Another release'.str_repeat("\n\nA short teaser.", 10)]);
    Storage::disk('local')->put($short->original_path, $page);
    Storage::disk('local')->put($other->original_path, str_replace('Ammonia burner programme', 'Another release', $page));
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(screeningAnswer(['decision' => 'REJECT', 'primary_reason' => 'OPINION_ONLY', 'evidence' => '', 'reason' => '具体的な内容がない。'])),
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['content' => 'section.body', 'date' => '', 'remove' => '', 'fixed_text' => ''])]]]]),
    ]);

    $screening = screenDocument($short);

    expect($screening->decision)->toBe('reject')
        ->and($screening->status_message)->toContain('文書の設定を改訂し（本文: section.body）、2 件をスクリーニングし直します')
        ->and($source->refresh()->document_config['content'])->toBe('section.body')
        ->and($short->refresh()->hasShortBody())->toBeFalse()->and($short->markdown)->toContain('The long body of the release.')
        ->and($other->refresh()->hasShortBody())->toBeFalse();
    // The run itself (faked) and the two cured documents.
    Queue::assertPushed(ScreenDocument::class, 3);
    Queue::assertPushed(ScreenDocument::class, fn (ScreenDocument $job): bool => $job->screening->document->is($other) && $job->screening->pass === 1);

    // When the agent's proposal does not cure it, the reject stands with a note and nothing is queued.
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(screeningAnswer(['decision' => 'REJECT', 'primary_reason' => 'OPINION_ONLY', 'evidence' => '', 'reason' => ''])),
        'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode(['content' => 'header', 'date' => '', 'remove' => '', 'fixed_text' => ''])]]]]),
    ]);
    $stubborn = Document::factory()->fetched()->for($source)->create(['original_path' => "documents/{$source->id}/3.html", 'markdown' => '# Short'.str_repeat("\n\nA short teaser.", 5)]);
    Storage::disk('local')->put($stubborn->original_path, '<html><body><header><h1>Short</h1><p>'.str_repeat('A short teaser. ', 10).'</p></header></body></html>');

    $screening = screenDocument($stubborn);
    expect($screening->status_message)->toContain('改訂を試みましたが、できませんでした')->and($source->refresh()->document_config['content'])->toBe('section.body');
    Queue::assertNotPushed(ScreenDocument::class, fn (ScreenDocument $job): bool => $job->screening->document->is($stubborn) && ! $job->screening->is($screening));
});

// A person's verdict (人の判定) is recorded on the document, shows before the screening's on the lists, outranks it at the gate, and can be withdrawn.
it('records a human decision that outranks the screening', function () {
    $document = Document::factory()->fetched()->create(['title' => 'Overruled doc']);
    Screening::factory()->for($document)->rejected()->create();

    Livewire::test('pages::editorial.documents.show', ['document' => $document->refresh()])->assertSet('humanDecision', '')
        ->call('decide')->assertHasErrors(['humanDecision'])
        ->set('humanDecision', 'adopt')->set('humanReason', '量産ラインの建設が本文にある')->call('decide')->assertHasNoErrors();

    expect($document->refresh())->toMatchArray(['human_decision' => 'adopt', 'human_reason' => '量産ラインの建設が本文にある'])
        ->and($document->human_decided_at)->not->toBeNull()->and($document->humanDecider?->is(auth()->user()))->toBeTrue()
        ->and($document->decision())->toBe('adopt')->and($document->isRejected())->toBeFalse();

    // The lists follow the decision that stands.
    $titles = fn ($component) => $component->instance()->documents->pluck('title')->all();
    $component = Livewire::test('pages::editorial.documents.index')->assertSee('人の判定：量産ラインの建設が本文にある');
    expect($titles($component->set('decision', 'adopt')))->toBe(['Overruled doc'])
        ->and($titles($component->set('decision', 'reject')))->toBe([]);

    // The material goes on for a document a person adopted, screening notwithstanding; the bulk extraction takes it too.
    Queue::fake();
    Livewire::test('pages::editorial.materials.index')->call('extract');
    Queue::assertPushed(ExtractMaterial::class, fn (ExtractMaterial $job): bool => $job->material->document->is($document));

    // Withdrawn, the screening's reject stands again.
    Livewire::test('pages::editorial.documents.show', ['document' => $document->refresh()])->call('undecide');
    expect($document->refresh()->human_decision)->toBeNull()->and($document->isRejected())->toBeTrue();
});

// A rejected document is stopped at the gate: the material job refuses it; an adopted or reviewed one goes on.
it('keeps a rejected document from the material', function () {
    $rejected = Document::factory()->fetched()->create();
    Screening::factory()->for($rejected)->rejected()->create();

    $material = ExtractMaterial::queueFor($rejected);
    (new ExtractMaterial($material))->handle(app(ProposeMaterial::class), app(ValidateMaterial::class));

    expect($material->refresh())->toMatchArray(['status' => 'failed', 'status_message' => 'スクリーニングで不採用になった文書です。'])
        ->and($rejected->refresh()->isRejected())->toBeTrue();
    Http::assertNothingSent();
});

// The screens: the bulk button queues the fetched documents not screened yet; the list shows and filters by the decision; the detail shows the run and screens again with a chosen model.
it('queues screenings from the screens and shows the decisions', function () {
    Queue::fake();
    $prompt = Prompt::factory()->create();
    $adopted = Document::factory()->fetched()->create(['title' => 'Adopted doc']);
    Screening::factory()->for($adopted)->for($prompt, 'prompt')->create(['reason' => '実環境での実証']);
    $reviewed = Document::factory()->fetched()->create(['title' => 'Reviewed doc']);
    Screening::factory()->for($reviewed)->for($prompt, 'prompt')->review()->create();
    $fresh = Document::factory()->fetched()->create(['title' => 'Fresh doc']);
    Document::factory()->fetched()->create(['title' => 'Excluded doc', 'excluded_by' => '発売']);
    Document::factory()->create(['title' => 'Unfetched doc']);

    Livewire::test('pages::editorial.documents.index')->call('screenDocuments');
    Queue::assertPushed(ScreenDocument::class, 1);
    expect($fresh->refresh()->screening)->toMatchArray(['status' => 'screening', 'model' => 'gpt-5.6-terra']);

    $titles = fn ($component) => $component->instance()->documents->pluck('title')->all();
    $component = Livewire::test('pages::editorial.documents.index')->assertSee('採用')->assertSee('要確認')->assertSee('判定中')->assertSee('実環境での実証');
    expect($titles($component->set('decision', 'adopt')))->toBe(['Adopted doc'])
        ->and($titles($component->set('decision', 'review')))->toBe(['Reviewed doc'])
        ->and($titles($component->set('decision', 'none')))->toBe(['Excluded doc', 'Unfetched doc']);

    // Review with a higher model from the document's screen: the next model up is proposed for a 要確認 document.
    Livewire::test('pages::editorial.documents.show', ['document' => $adopted->refresh()])->assertSet('screeningModel', 'gpt-5.6-terra');
    Livewire::test('pages::editorial.documents.show', ['document' => $reviewed->refresh()])->assertSet('screeningModel', 'gpt-5.6-sol')->assertSee('INSUFFICIENT_EVIDENCE')
        ->set('screeningModel', 'gpt-6-astra')->call('screen')->assertHasNoErrors();
    Queue::assertPushed(ScreenDocument::class, 2);
    expect($reviewed->refresh()->screening)->toMatchArray(['status' => 'screening', 'model' => 'gpt-6-astra'])
        ->and($reviewed->screenings()->count())->toBe(2);

    // The figures per prompt version on the list.
    $figures = Livewire::test('pages::editorial.documents.index')->assertSee('プロンプト版')->instance()->screeningFigures;
    expect($figures['versions'][0])->toMatchArray(['screened' => 2, 'adopted' => 1, 'rejected' => 0, 'reviewed' => 1])
        ->and(round($figures['versions'][0]['cache_hit_rate'], 3))->toBe(round(4000 / 6000, 3))
        // The reasons count the latest screening of each document: the reviewed one is being screened again, so its reason is out for now.
        ->and(collect($figures['reasons'])->where('count', '>', 0)->pluck('count', 'primary_reason')->all())->toBe(['DEMONSTRATION' => 1])
        ->and(count($figures['reasons']))->toBe(17)->and($figures['reasons'][0])->toMatchArray(['primary_reason' => 'FRONTIER_BREAK', 'decision' => 'adopt', 'count' => 0]);
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
    // 性能の数値目標がない賞金コンテストでも、能力ギャップとなぜ今かがあれば兆しとして採用する (2026-09-22, DARPA D2 Sprint が EVENT_PR で落ちていた)。
    'Case 8: 数値目標のない賞金Competition' => ["# 賞金100万ドルでAI医療記録・意思決定支援を加速\n\n大規模戦闘における外傷は依然として深刻な脅威であり、病院前の戦闘負傷者ケアの75%は記録されていない。DARPAはこの制約に対処するため、賞金100万ドルのD2 Sprintを開始する。負傷者を自動で評価し、処置を推奨・誘導し、質を監視し、医療行為を記録するソフトウェアの開発を促す。「混乱した戦場で医療データを取得し、瞬時に臨床判断を下すことは、専門家でない者には極めて難しい」とProgram Managerは述べた。AI駆動型医療ツールの急速な進歩を背景に、記録Trackと意思決定支援Trackの二つを設け、各1位30万ドル。2026年9月10日にチーム資格審査を開始し、最終提出は2027年3月1日。MIT Lincoln Labs、AFRL、JHU APL等と共同で実施する。", 'adopt', 'FEASIBILITY_BET'],
])->skip(env('SCREENING_ACCEPTANCE') !== '1', 'Runs against the real model only with SCREENING_ACCEPTANCE=1.');
