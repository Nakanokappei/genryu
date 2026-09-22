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

const MATERIAL_POLICY = "一次情報から何が変わったのかを見つけ、編集のレンズから分析する。\n\n根拠をもって出せるレンズだけを出力する。\n";

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
 * A claim of each kind: one read off the document, one from the model's
 * own general knowledge, one drawn from both.
 */
function materialClaim(string $type = 'primary_source', string $statement = 'NEDO が小型アンモニア燃焼器の開発事業を開始した。'): array
{
    return ['statement' => $statement, 'type' => $type, 'confidence' => 'high', 'basis' => '本文「NEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。」'];
}

/**
 * A whole answer for MATERIAL_MARKDOWN, as the transport shape: one lens
 * that holds, one transition, one recommended angle.
 */
function materialDossier(array $overrides = []): array
{
    return [
        'editorial_lenses' => [[
            'lens' => 'money',
            'strength' => 'STRONG',
            'before' => '研究テーマとして扱われていた。',
            'change' => '20 億円の開発事業が始まった。',
            'after' => '工業炉向けの燃焼器が成立すれば、供給網の競争が始まりうる。',
            'tension' => 'Research ↔ Capital',
            'angle' => '研究段階だったアンモニア燃焼に、有限の資本が入った。',
            'reason' => '予算と期間が一次情報にある。',
            'claims' => [materialClaim(), materialClaim('general_knowledge', 'アンモニアは燃焼時に CO2 を出さないが燃焼速度が遅い。')],
        ]],
        'technology_transition' => [[
            'previous_state' => 'Technically Feasible',
            'current_state' => 'Economically Plausible',
            'transition' => '研究から開発事業へ。',
            'what_changed' => '4 年 20 億円の事業として着手された。',
            'why_it_matters' => '工業炉の脱炭素の道筋が一つ増える。',
            'confidence' => 'medium',
            'evidence' => [materialClaim()],
        ]],
        'recommended_angles' => [[
            'rank' => 1,
            'lens' => 'money',
            'angle' => '研究段階だったアンモニア燃焼に、有限の資本が入った。',
            'editorial_thesis' => '資本が入った時点が、技術の状態が動いた合図である。',
            'why_strong' => '金額と期間が一次情報で確認できる。',
            'primary_evidence' => [materialClaim()],
            'uncertainties' => ['混焼率 85％ の達成条件は示されていない。'],
        ]],
        'missing_information' => ['実証設備の規模'],
        'next_signals' => ['パイロット設備の着工'],
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

// The dossier: the lenses that hold, the angles they give, and every statement saying where it comes from — which is what this PoC is out to test.
it('keeps the lenses that hold, the angles they give and where every statement comes from', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(materialAnswer(materialDossier()))]);
    $document = Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN, 'title' => 'アンモニア燃焼器の開発を開始']);
    Screening::factory()->for($document)->create();

    $material = extractMaterial($document);

    expect($material->status)->toBe('extracted')
        ->and($material->validation)->toBe([])
        ->and($material->document_revision_id)->toBe($document->revisions()->sole()->id)
        ->and($material->prompt?->name)->toBe('structuring')
        ->and($material->model)->toBe(EditorialPolicy::DEFAULT_MODEL)
        // The lenses are kept by name, the single transition unwrapped, and nothing empty is kept.
        ->and(array_keys($material->lenses()))->toBe(['money'])
        ->and($material->data['technology_transition']['current_state'])->toBe('Economically Plausible')
        ->and($material->data['recommended_angles'][0]['angle'])->toBe('研究段階だったアンモニア燃焼に、有限の資本が入った。')
        ->and($material->claimTypes())->toBe(['primary_source' => 3, 'general_knowledge' => 1, 'inference' => 0])
        ->and($material->input_tokens)->toBe(2000)->and($material->cached_tokens)->toBe(1500);

    // The policy is the cached block, the document follows as material to analyse, and the lenses are a list of what holds, not eight slots.
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['input'][0]['content'][0]['text'] === MATERIAL_POLICY
            && isset($body['input'][0]['content'][0]['prompt_cache_breakpoint'])
            && str_contains($body['input'][2]['content'], 'NEDO は、工業炉向けの')
            && $body['text']['format']['schema']['properties']['editorial_lenses']['type'] === 'array'
            && $body['text']['format']['schema']['properties']['editorial_lenses']['items']['properties']['lens']['enum'] === ProposeMaterial::LENSES;
    });

    // The screens show the angle, the lens with its before / change / after, and where each statement comes from.
    $this->get(route('materials.show', $material))->assertSee('研究段階だったアンモニア燃焼に、有限の資本が入った。')->assertSee('MONEY — 資本')->assertSee('一次情報')->assertSee('一般知識');
    $this->get(route('materials.index'))->assertSee('一次情報 3 / 一般知識 1 / 推論 0');
});

// A lens that does not hold together is the error worth a call: the agent gets the errors and one more go.
it('checks the dossier holds together and repairs once', function () {
    $wrong = materialDossier(['editorial_lenses' => [[
        'lens' => 'money',
        'strength' => 'MEDIUM',
        'before' => '研究テーマだった。',
        'change' => '',
        'after' => '',
        'tension' => '',
        'angle' => '資本が入った。',
        'reason' => '',
        'claims' => [materialClaim('general_knowledge')],
    ]]]);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(materialAnswer($wrong))
        ->push(materialAnswer(materialDossier()))]);

    expect(extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]))->status)->toBe('extracted');

    Http::assertSent(fn (Request $request): bool => str_contains($request->data()['input'][1]['content'], 'money: no claim of type primary_source'));
    expect(Http::recorded())->toHaveCount(2);
});

// A repair that still fails leaves the material failed, with the checks' report kept.
it('fails with the report when the checks do not pass twice', function () {
    $broken = materialDossier(['recommended_angles' => [['rank' => 2, 'lens' => 'factory', 'angle' => 'x', 'editorial_thesis' => 'x', 'why_strong' => 'x', 'primary_evidence' => [materialClaim()], 'uncertainties' => []]]]);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push(materialAnswer($broken))->push(materialAnswer($broken))]);

    $material = extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]));

    expect($material->status)->toBe('failed')
        ->and($material->status_message)->toContain('素材情報が検査を通りませんでした')
        ->and($material->validation)->toContain('recommended_angles: the ranks must run 1, 2, 3 in order')
        ->and($material->validation)->toContain('recommended_angles #1: lens "factory" is not among the lenses kept')
        ->and($material->data)->toBeNull();
    $this->get(route('materials.show', $material))->assertSee('直近の抽出が通らなかった検査');
});

// The checks in their own right: a lens must be a whole story resting on this document, and a claim must say where it comes from.
it('checks a lens is a whole story resting on the primary source', function () {
    $validate = app(ValidateMaterial::class);
    $dossier = ProposeMaterial::dossier(materialDossier());

    expect($validate($dossier))->toBe([]);

    $lens = $dossier['editorial_lenses']['money'];
    expect($validate(['editorial_lenses' => ['money' => [...$lens, 'claims' => [materialClaim('inference')]]]]))
        ->toBe(['money: no claim of type primary_source; the change must touch this document']);
    expect($validate(['editorial_lenses' => ['money' => array_diff_key($lens, ['after' => null, 'tension' => null])]]))
        ->toBe(['money: after is missing; drop the lens or fill it', 'money: tension is missing; drop the lens or fill it']);
    expect($validate(['editorial_lenses' => ['money' => [...$lens, 'claims' => [[...materialClaim(), 'basis' => ' ']]]]]))
        ->toBe(['money, claim 1: no basis; say what it rests on']);
    expect($validate(['technology_transition' => ['current_state' => '工業化', 'evidence' => []]]))
        ->toBe(['technology_transition.current_state: "工業化" is not one of the lifecycle states']);
});

it('does not ask the agent about a document that has not been fetched', function () {
    $material = extractMaterial(Document::factory()->create());

    expect($material->status)->toBe('failed')->and($material->status_message)->toContain('取得されていません');
    Http::assertNothingSent();
});

// The structuring layer and its model are set above the materials, on the 素材情報 screen.
it('reads the structuring layer and its model from the materials screen', function () {
    EditorialPolicy::query()->delete();
    expect(EditorialPolicy::bodyFor('structuring'))->toContain('Editorial Lens')
        ->and(EditorialPolicy::modelFor('structuring'))->toBe(EditorialPolicy::DEFAULT_MODEL);

    Livewire::test('pages::materials.index')
        ->set('structuring', MATERIAL_POLICY)->set('structuringModel', 'gpt-6-astra')
        ->call('saveStructuring')->assertHasNoErrors();

    expect(EditorialPolicy::bodyFor('structuring'))->toBe(MATERIAL_POLICY)
        ->and(EditorialPolicy::modelFor('structuring'))->toBe('gpt-6-astra');

    Http::fake(['api.openai.com/v1/responses' => Http::response(materialAnswer(materialDossier()))]);
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
