<?php

use App\Actions\CollectFigures;
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

const MATERIAL_POLICY = "一次情報から、記事を書くための素材を作る。\n\n書けないものは出力しない。\n";

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
 * A whole answer for MATERIAL_MARKDOWN: the six parts of an article.
 */
function materialParts(array $overrides = []): array
{
    return [
        'angle' => 'アンモニア燃焼の課題は「燃やせるか」から「分解炉全体を回せるか」へ移った',
        'before' => 'アンモニアは燃焼速度が遅く、工業炉の主熱源には使えないとされてきた',
        'change' => 'NEDO が工業炉向けの小型アンモニア燃焼器の開発事業を開始した',
        'after' => '燃料製造の炭素強度が解ければ、工業炉の脱炭素が商用規模で視野に入る',
        'facts' => ['事業期間は 2026 年度から 2029 年度までの 4 年間', '予算は 20 億円', '混焼率は 85％ を目標'],
        'background' => ['アンモニアは燃焼時に CO2 を出さないが、燃焼速度が遅く窒素酸化物が出やすい'],
        'winners' => ['既設の工業炉を使い続けたい化学メーカー'],
        'losers' => ['燃料としての天然ガスを売る側'],
        'future_society' => ['石油化学コンビナートの煙突から出る CO2 が減る'],
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

// The material is the parts of an article: the angle it would be written on, the change it rests on, the facts of the document and the background from the model's own knowledge.
it('writes the parts of an article and counts what came from each side', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(materialAnswer(materialParts()))]);
    $document = Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN, 'title' => 'アンモニア燃焼器の開発を開始']);
    Screening::factory()->for($document)->create();

    $material = extractMaterial($document);

    expect($material->status)->toBe('extracted')
        ->and($material->validation)->toBe([])
        ->and($material->document_revision_id)->toBe($document->revisions()->sole()->id)
        ->and($material->prompt?->name)->toBe('structuring')
        ->and($material->model)->toBe(EditorialPolicy::DEFAULT_MODEL)
        ->and(array_keys($material->parts()))->toBe(ProposeMaterial::PARTS)
        ->and($material->data['angle'])->toBe('アンモニア燃焼の課題は「燃やせるか」から「分解炉全体を回せるか」へ移った')
        ->and($material->counts())->toBe(['primary_source' => 3, 'general_knowledge' => 1, 'inference' => 3])
        ->and($material->input_tokens)->toBe(2000)->and($material->cached_tokens)->toBe(1500)
        // A document without figures leaves the part out.
        ->and($material->data)->not->toHaveKey('figures');

    // The policy is the cached block, the document follows as material to analyse, and the schema asks for the six parts and nothing about the answer itself.
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['input'][0]['content'][0]['text'] === MATERIAL_POLICY
            && isset($body['input'][0]['content'][0]['prompt_cache_breakpoint'])
            && str_contains($body['input'][2]['content'], 'NEDO は、工業炉向けの')
            // The angle is asked for last, after the facts it should rest on.
            && array_keys($body['text']['format']['schema']['properties']) === ['before', 'change', 'after', 'facts', 'background', 'winners', 'losers', 'future_society', 'angle'];
    });

    // The detail screen shows each part and how many lines came from each side; the list shows the document and its state.
    $this->get(route('editorial.materials.show', $material))->assertSee('「燃やせるか」から「分解炉全体を回せるか」へ移った')->assertSee('予算は 20 億円')->assertSee('事実（一次情報）')->assertSee('背景（一般知識）')->assertSee('一次情報 3 / 一般知識 1 / 推論 3');
    $this->get(route('editorial.materials.index'))->assertSee('アンモニア燃焼器の開発を開始')->assertSee('抽出済み')->assertSee(__('rows per page'));
});

// A part the model could not write is dropped rather than kept as an empty or hedged value.
it('drops what came back empty', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(materialAnswer(materialParts(['before' => null, 'after' => '  ', 'background' => []])))]);

    $material = extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]));

    expect($material->status)->toBe('extracted')
        ->and(array_keys($material->parts()))->toBe(['angle', 'change', 'facts', 'winners', 'losers', 'future_society'])
        ->and($material->counts())->toBe(['primary_source' => 3, 'general_knowledge' => 0, 'inference' => 3]);
});

// A material without an angle, a change or the facts is no use to an article: the agent gets the errors and one more go.
it('checks the parts an article needs are there and repairs once', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(materialAnswer(materialParts(['angle' => null, 'facts' => []])))
        ->push(materialAnswer(materialParts()))]);

    expect(extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]))->status)->toBe('extracted');

    Http::assertSent(fn (Request $request): bool => str_contains($request->data()['input'][1]['content'], 'angle: missing; an article cannot be written without it'));
    expect(Http::recorded())->toHaveCount(2);
});

// A repair that still fails leaves the material failed, with the checks' report kept.
it('fails with the report when the checks do not pass twice', function () {
    $broken = materialParts(['change' => null]);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push(materialAnswer($broken))->push(materialAnswer($broken))]);

    $material = extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]));

    expect($material->status)->toBe('failed')
        ->and($material->status_message)->toContain('素材情報が検査を通りませんでした')
        ->and($material->validation)->toContain('change: missing; an article cannot be written without it')
        ->and($material->data)->toBeNull();
    $this->get(route('editorial.materials.show', $material))->assertSee('直近の抽出が通らなかった検査');
});

it('does not ask the agent about a document that has not been fetched', function () {
    $material = extractMaterial(Document::factory()->create());

    expect($material->status)->toBe('failed')->and($material->status_message)->toContain('取得されていません');
    Http::assertNothingSent();
});

// The structuring layer and its model are set above the materials, on the 素材情報 screen.
it('reads the structuring layer and its model from the materials screen', function () {
    EditorialPolicy::query()->delete();
    expect(EditorialPolicy::bodyFor('structuring'))->toContain('- angle:')
        ->and(EditorialPolicy::modelFor('structuring'))->toBe(EditorialPolicy::DEFAULT_MODEL);

    Livewire::test('pages::editorial.materials.index')
        ->set('structuring', MATERIAL_POLICY)->set('structuringModel', 'gpt-6-astra')
        ->call('saveStructuring')->assertHasNoErrors();

    expect(EditorialPolicy::bodyFor('structuring'))->toBe(MATERIAL_POLICY)
        ->and(EditorialPolicy::modelFor('structuring'))->toBe('gpt-6-astra');

    Http::fake(['api.openai.com/v1/responses' => Http::response(materialAnswer(materialParts()))]);
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

    Livewire::test('pages::editorial.materials.index')->call('extract');

    Queue::assertPushed(ExtractMaterial::class, 2);
    expect($unscreened->refresh()->material)->toBeNull()->and($rejected->refresh()->material)->toBeNull();
    $this->get(route('editorial.documents.show', $rejected))->assertSee('スクリーニングで不採用になった文書です。');
    expect($missing->material?->status)->toBe('extracting')
        ->and($failed->material()->sole()->status)->toBe('extracting')
        ->and($extracted->material()->sole()->status)->toBe('extracted')
        ->and(Material::query()->count())->toBe(3);

    Livewire::test('pages::editorial.documents.show', ['document' => $extracted])->call('extract');
    Livewire::test('pages::editorial.materials.show', ['material' => $extracted->material()->sole()])->call('extract');

    Queue::assertPushed(ExtractMaterial::class, 4);
    expect($extracted->material()->sole()->status)->toBe('extracting')
        ->and(Material::query()->count())->toBe(3);
});

// The figures are gathered from the document's Markdown, not asked of the model: each with its alt text and caption, icons and logos left out, one shown twice kept once.
it('gathers the figures of the source into the material', function () {
    $markdown = MATERIAL_MARKDOWN."\n\n![](https://example.jp/fig1.jpg#lz:xlarge)\n\n本文の続き。\n\n![図1 燃焼器の構造](https://example.jp/fig1.jpg)\n\n図1 燃焼器の構造\n\n- ![図2 試験炉](https://example.jp/fig2.png)図2 試験炉の全景\n\n[PDF ![](https://example.jp/common/icon_pdf.png)](https://example.jp/a.pdf)\n\n- ![ロゴ](https://example.jp/800068828.jpg)";

    expect(CollectFigures::from($markdown))->toBe([
        ['url' => 'https://example.jp/fig1.jpg', 'alt' => '図1 燃焼器の構造', 'caption' => '図1 燃焼器の構造'],
        ['url' => 'https://example.jp/fig2.png', 'alt' => '図2 試験炉', 'caption' => '図2 試験炉の全景'],
    ]);

    Http::fake(['api.openai.com/v1/responses' => Http::response(materialAnswer(materialParts()))]);
    $material = extractMaterial(Document::factory()->fetched()->create(['markdown' => $markdown]));

    expect($material->status)->toBe('extracted')
        ->and($material->figures())->toHaveCount(2)
        // The figures sit beside the parts, not among them.
        ->and(array_keys($material->parts()))->toBe(ProposeMaterial::PARTS);
    $this->get(route('editorial.materials.show', $material))->assertSee('図版')->assertSee('図2 試験炉の全景')->assertSee('https://example.jp/fig2.png');
});
