<?php

use App\Actions\ProposeMaterial;
use App\Actions\ValidateMaterial;
use App\Jobs\ExtractMaterial;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\Screening;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const MATERIAL_MARKDOWN = "# アンモニア燃焼器の開発を開始\n\n2026-09-17\n\nNEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。事業期間は 2026 年度から 2029 年度までの 4 年間である。\n\n予算は 20 億円を予定している。混焼率は 85％ を目標とする。";

const MATERIAL_POLICY = "文書を次の観点で整理する。\n\n- 要約: 3 文以内\n- 重要な事実: 数値や日付の一覧\n- 背景: 理解に必要な前提知識\n";

/**
 * What the agent would answer, as the Responses API wire format.
 */
function materialAnswer(array $json): array
{
    return [
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]]],
        'usage' => ['input_tokens' => 2000, 'input_tokens_details' => ['cached_tokens' => 1500, 'cache_write_tokens' => 0], 'output_tokens' => 400],
    ];
}

/**
 * A whole answer for MATERIAL_MARKDOWN and MATERIAL_POLICY: two items
 * quoted from the document, one filled from the model's knowledge.
 */
function materialItems(array $overrides = []): array
{
    return [
        '要約' => [
            'value' => 'NEDO が工業炉向けの小型アンモニア燃焼器の開発事業を開始した。期間は 4 年、予算は 20 億円。',
            'source' => 'document',
            'quotes' => [['line_start' => 5, 'line_end' => 5, 'quote' => 'NEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。']],
        ],
        '重要な事実' => [
            'value' => ['予算 20 億円', '混焼率 85％ が目標'],
            'source' => 'document',
            'quotes' => [['line_start' => 7, 'line_end' => 7, 'quote' => '予算は 20 億円を予定している。']],
        ],
        '背景' => [
            'value' => 'アンモニアは燃焼時に CO2 を出さないが、燃焼速度が遅く窒素酸化物が出やすい。',
            'source' => 'knowledge',
            'quotes' => [],
        ],
        ...$overrides,
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.openai.key' => 'test-key']);
    EditorialPolicy::query()->create(['layer' => 'structuring', 'body' => MATERIAL_POLICY]);
    $this->actingAs(User::factory()->create());
});

function extractMaterial(Document $document): Material
{
    $material = ExtractMaterial::queueFor($document);
    (new ExtractMaterial($material))->handle(app(ProposeMaterial::class), app(ValidateMaterial::class));

    return $material->refresh();
}

// Every item of the policy is filled, each saying where it came from: the document, with the lines it quotes, or the model's general knowledge — which is what this PoC is out to test.
it('fills every item of the policy and keeps where each one came from', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(materialAnswer(materialItems()))]);
    $document = Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN, 'title' => 'アンモニア燃焼器の開発を開始']);
    Screening::factory()->for($document)->create();

    $material = extractMaterial($document);

    expect($material->status)->toBe('extracted')
        ->and($material->validation)->toBe([])
        ->and($material->document_revision_id)->toBe($document->revisions()->sole()->id)
        ->and($material->prompt?->name)->toBe('structuring')
        ->and($material->model)->toBe(EditorialPolicy::DEFAULT_MODEL)
        ->and(array_keys($material->items()))->toBe(['要約', '重要な事実', '背景'])
        ->and($material->items()['背景']['source'])->toBe('knowledge')
        ->and($material->sources())->toBe(['document' => 2, 'knowledge' => 1, 'none' => 0])
        ->and($material->input_tokens)->toBe(2000)->and($material->cached_tokens)->toBe(1500);

    // The policy is the cached block, the document's lines are numbered after it, and the schema asks for the policy's items.
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['input'][0]['content'][0]['text'] === MATERIAL_POLICY
            && isset($body['input'][0]['content'][0]['prompt_cache_breakpoint'])
            && str_contains($body['input'][2]['content'], '5| NEDO は、工業炉向けの')
            && array_keys($body['text']['format']['schema']['properties']) === ['要約', '重要な事実', '背景'];
    });

    // The screens show each item with its source and the lines it quotes.
    $this->get(route('materials.show', $material))->assertSee('NEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。')->assertSee('5 行')->assertSee('知識由来')->assertSee('文書由来');
    $this->get(route('materials.index'))->assertSee('文書由来 2 / 知識由来 1');
});

// A quote that is not in the lines it names is the one error worth a call: the agent gets the errors and one more go.
it('checks the quotes against the document and repairs once', function () {
    $wrong = materialItems(['要約' => [
        'value' => 'NEDO が水素燃焼器の開発事業を開始した。',
        'source' => 'document',
        'quotes' => [['line_start' => 5, 'line_end' => 5, 'quote' => 'NEDO は水素燃焼器の開発事業を開始した。']],
    ]]);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(materialAnswer($wrong))
        ->push(materialAnswer(materialItems()))]);

    expect(extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]))->status)->toBe('extracted');

    Http::assertSent(fn (Request $request): bool => str_contains($request->data()['input'][1]['content'], '要約, quote 1: the quote is not found verbatim in lines 5-5'));
    expect(Http::recorded())->toHaveCount(2);
});

// A repair that still fails leaves the material failed, with the checks' report kept.
it('fails with the report when the checks do not pass twice', function () {
    $broken = materialItems(['重要な事実' => ['value' => ['予算'], 'source' => 'document', 'quotes' => [['line_start' => 99, 'line_end' => 99, 'quote' => 'x']]]]);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push(materialAnswer($broken))->push(materialAnswer($broken))]);

    $material = extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]));

    expect($material->status)->toBe('failed')
        ->and($material->status_message)->toContain('素材情報が検査を通りませんでした')
        ->and($material->validation)->toContain('重要な事実, quote 1: line range 99-99 is outside the document (1-7)')
        ->and($material->data)->toBeNull();
    $this->get(route('materials.show', $material))->assertSee('直近の抽出が通らなかった検査');
});

// The checks in their own right: the document and the model's knowledge are told apart, and an item cannot claim both or neither.
it('tells the document and general knowledge apart', function () {
    $validate = app(ValidateMaterial::class);
    $document = Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]);
    $revision = $document->revisions()->sole();
    $items = ['要約', '重要な事実', '背景'];

    expect($validate(materialItems(), $items, $revision))->toBe([]);
    expect($validate(materialItems(['背景' => ['value' => 'x', 'source' => 'document', 'quotes' => []]]), $items, $revision))
        ->toBe(['背景: source is document but no quote is given']);
    expect($validate(materialItems(['背景' => ['value' => 'x', 'source' => 'knowledge', 'quotes' => [['line_start' => 5, 'line_end' => 5, 'quote' => 'NEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。']]]]), $items, $revision))
        ->toBe(['背景: source is knowledge, which takes no quote']);
    expect($validate(materialItems(['背景' => ['value' => null, 'source' => 'knowledge', 'quotes' => []]]), $items, $revision))
        ->toBe(['背景: no value, so the source must be none']);
    expect($validate(['要約' => materialItems()['要約']], $items, $revision))
        ->toBe(['重要な事実: the item is missing', '背景: the item is missing']);
});

it('does not ask the agent about a document that has not been fetched', function () {
    $material = extractMaterial(Document::factory()->create());

    expect($material->status)->toBe('failed')->and($material->status_message)->toContain('取得されていません');
    Http::assertNothingSent();
});

// The structuring layer and its model are set on 編集方針; its items are what a material must have.
it('reads the structuring layer and its model from the editorial policy screen', function () {
    EditorialPolicy::query()->delete();
    expect(EditorialPolicy::items(EditorialPolicy::bodyFor('structuring')))->toBe(['要約', '発表主体', '発表の種類', '技術領域', '重要な事実', '関係者', '意義', '背景', '記事の切り口'])
        ->and(EditorialPolicy::modelFor('structuring'))->toBe(EditorialPolicy::DEFAULT_MODEL);

    Livewire::test('pages::editorial-policy.index')
        ->set('structuring', MATERIAL_POLICY)->set('structuringModel', 'gpt-6-astra')
        ->call('save')->assertHasNoErrors();

    expect(EditorialPolicy::bodyFor('structuring'))->toBe(MATERIAL_POLICY)
        ->and(EditorialPolicy::modelFor('structuring'))->toBe('gpt-6-astra');

    Http::fake(['api.openai.com/v1/responses' => Http::response(materialAnswer(materialItems()))]);
    $material = extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]));
    expect($material->status)->toBe('extracted')->and($material->model)->toBe('gpt-6-astra');
});

// The gate: in bulk, only the documents the screening adopted go on; a rejected one is never extracted, not even from its own screen.
it('queues the missing and failed materials of adopted documents, and one material again, from the screens', function () {
    Queue::fake();
    $missing = Document::factory()->fetched()->create();
    Screening::factory()->for($missing)->create();
    $failed = Document::factory()->fetched()->create();
    Screening::factory()->for($failed)->create();
    Material::factory()->for($failed)->create(['status' => 'failed', 'data' => null]);
    $extracted = Document::factory()->fetched()->create();
    Screening::factory()->for($extracted)->create();
    Material::factory()->for($extracted)->create();
    Document::factory()->create(['status' => 'fetching']);
    $unscreened = Document::factory()->fetched()->create();
    $rejected = Document::factory()->fetched()->create();
    Screening::factory()->for($rejected)->rejected()->create();

    Livewire::test('pages::materials.index')->call('extract');

    Queue::assertPushed(ExtractMaterial::class, 2);
    expect($unscreened->refresh()->material)->toBeNull()->and($rejected->refresh()->material)->toBeNull();
    $this->get(route('documents.show', $rejected))->assertSee('スクリーニングで不採用になった文書です。');
    expect($missing->material?->status)->toBe('extracting')
        ->and($failed->material()->sole()->status)->toBe('extracting')
        ->and($extracted->material()->sole()->status)->toBe('extracted')
        ->and(Material::query()->count())->toBe(3);

    Livewire::test('pages::documents.show', ['document' => $extracted])->call('extract');
    Livewire::test('pages::materials.show', ['material' => $extracted->material()->sole()])->call('extract');

    Queue::assertPushed(ExtractMaterial::class, 4);
    expect($extracted->material()->sole()->status)->toBe('extracting')
        ->and(Material::query()->count())->toBe(3);
});
