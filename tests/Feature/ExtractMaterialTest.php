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

/**
 * What the agent would answer for the extract phase, as the Responses API wire format.
 */
function materialAnswer(array $json): array
{
    return [
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]]],
        'usage' => ['input_tokens' => 2000, 'input_tokens_details' => ['cached_tokens' => 1500, 'cache_write_tokens' => 0], 'output_tokens' => 400],
    ];
}

/**
 * A whole extract answer for MATERIAL_MARKDOWN: two spans quoted from its lines, the claims on them, the facets.
 */
function materialExtract(array $overrides = []): array
{
    return [
        'source_language' => 'ja',
        'primary_spans' => [
            ['id' => 'p1', 'line_start' => 5, 'line_end' => 5, 'quote' => 'NEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。'],
            ['id' => 'p2', 'line_start' => 7, 'line_end' => 7, 'quote' => '予算は 20 億円を予定している。'],
        ],
        'claims' => [
            claim('e1', 'primary_evidence', 'NEDO が小型アンモニア燃焼器の開発事業を開始した。', 'reported', ['p1']),
            claim('e2', 'primary_evidence', '予算は 20 億円を予定している。', 'reported', ['p2']),
        ],
        'primary_evidence' => [
            'what_happened' => ['status' => 'supported', 'claim_ids' => ['e1']],
            'key_facts' => ['status' => 'supported', 'claim_ids' => ['e1', 'e2']],
            'reported_claims' => ['status' => 'supported', 'claim_ids' => ['e2']],
            'numbers' => ['status' => 'supported', 'claim_ids' => ['e2']],
            'actors' => ['status' => 'unknown', 'claim_ids' => []],
        ],
        ...$overrides,
    ];
}

/**
 * A whole finalize answer resting on materialExtract().
 */
function materialFinalize(array $overrides = []): array
{
    return [
        'claims' => [
            claim('c1', 'inference', '事業の開始は、工業炉のアンモニア専焼が工学の課題になったことを示す。', 'inferred', ['e1']),
            claim('c2', 'inference', '予算規模は本格的な資源投入である。', 'inferred', ['e2']),
            claim('c3', 'inference', '目標の混焼率と現時点の実績の差が残っている。', 'inferred', ['e1', 'e2']),
        ],
        'technology_transition' => [
            'scope' => '工業炉向けアンモニア燃焼器',
            'previous_state' => 'extremely_hard',
            'current_state' => 'technically_feasible',
            'assessment' => 'observed_transition',
            'assessment_claim_ids' => ['c1'],
            'frontier_transition' => ['status' => 'supported', 'claim_ids' => ['c1']],
        ],
        'engineering' => [
            'capability' => ['status' => 'supported', 'claim_ids' => ['c1']],
            'mechanism' => ['status' => 'unknown', 'claim_ids' => []],
            'engineering_attack' => ['status' => 'supported', 'claim_ids' => ['c1']],
            'capital_commitment' => ['status' => 'supported', 'claim_ids' => ['c2']],
            'bottleneck' => ['status' => 'insufficient_evidence', 'claim_ids' => []],
            'industrialization' => ['status' => 'unknown', 'claim_ids' => []],
        ],
        'editorial' => [
            'why_it_matters' => ['status' => 'supported', 'claim_ids' => ['c1']],
            'tensions' => [[
                'id' => 't1', 'axis' => 'possible_affordable',
                'left_claim_ids' => ['e1'], 'right_claim_ids' => ['e2'], 'relationship_claim_ids' => ['c3'], 'status' => 'observed',
            ]],
            'possible_angles' => [[
                'id' => 'a1', 'angle' => '工業炉の脱炭素は、燃焼器の工学的課題に置き換わった',
                'entry_point' => 'frontier', 'lenses' => ['frontier', 'money'], 'tension_ids' => ['t1'],
                'angle_claim_id' => 'c1', 'why_now_primary_claim_ids' => ['e1'], 'supporting_primary_claim_ids' => ['e1', 'e2'],
                'counterpoint_claim_ids' => ['c3'], 'missing_information_ids' => ['g1'], 'reader_question' => 'なぜ今まで難しかったのか',
                'strength' => 'medium', 'decision' => 'candidate', 'decision_reason' => '一次資料に事業開始と予算の根拠がある',
            ]],
            'recommended_angle_id' => 'a1',
            'recommendation_reason' => '唯一の候補',
            'missing_information' => [['id' => 'g1', 'question' => '現時点の混焼率の実績', 'why_it_matters' => '目標と実績の差が分からない', 'related_claim_ids' => ['e2'], 'reason' => 'not_in_primary']],
            'next_signals' => [['id' => 's1', 'signal' => '実証炉での混焼率', 'observable_criterion' => '85％ の達成を示す報告', 'required_primary_evidence' => 'NEDO の成果報告', 'related_claim_ids' => ['c3'], 'target_state' => 'technically_feasible']],
            'reader_questions' => [['id' => 'r1', 'question' => 'なぜアンモニアなのか', 'answer_claim_ids' => [], 'status' => 'unanswered']],
        ],
        'quality' => ['warnings' => []],
        ...$overrides,
    ];
}

function claim(string $id, string $type, string $statement, string $status, array $basis): array
{
    return ['id' => $id, 'type' => $type, 'statement' => $statement, 'epistemic_status' => $status, 'basis' => $basis, 'confidence' => 'medium', 'confidence_reason' => '資料の記述による', 'limitations' => []];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.openai.key' => 'test-key']);
    $this->actingAs(User::factory()->create());
});

function extractMaterial(Document $document): Material
{
    $material = ExtractMaterial::queueFor($document);
    (new ExtractMaterial($material))->handle(app(ProposeMaterial::class), app(ValidateMaterial::class));

    return $material->refresh();
}

// The two passes: extract fixes the evidence with its quotes, finalize builds the analysis on those claims; the material keeps both, pinned to the revision, the prompt version and the model.
it('builds a material in two passes and keeps the evidence, the analysis and the usage', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(materialAnswer(materialExtract()))
        ->push(materialAnswer(materialFinalize()))]);
    $document = Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN, 'title' => 'アンモニア燃焼器の開発を開始']);
    Screening::factory()->for($document)->create();

    $material = extractMaterial($document);

    expect($material->status)->toBe('extracted')
        ->and($material->validation)->toBe([])
        ->and($material->document_revision_id)->toBe($document->revisions()->sole()->id)
        ->and($material->prompt?->name)->toBe('structuring')
        ->and($material->model)->toBe(EditorialPolicy::DEFAULT_MODEL)
        ->and($material->data['schema_version'])->toBe(ProposeMaterial::SCHEMA_VERSION)
        ->and($material->data['source_language'])->toBe('ja')
        ->and(array_column($material->data['claims'], 'id'))->toBe(['e1', 'e2', 'c1', 'c2', 'c3'])
        ->and($material->data['provenance']['primary_spans'][0]['quote'])->toContain('NEDO は、工業炉向けの')
        ->and($material->data['technology_transition']['assessment'])->toBe('observed_transition')
        ->and($material->recommendedAngle()['angle'])->toBe('工業炉の脱炭素は、燃焼器の工学的課題に置き換わった')
        // Both calls counted.
        ->and($material->input_tokens)->toBe(4000)->and($material->cached_tokens)->toBe(3000)->and($material->output_tokens)->toBe(800);

    // The document's lines are numbered for the model, the prompt is cached as one block, and the finalize pass gets the evidence back.
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return isset($body['input'][0]['content'][0]['prompt_cache_breakpoint'])
            && str_contains($body['input'][1]['content'], 'Phase: extract')
            && str_contains($body['input'][2]['content'], '5| NEDO は、工業炉向けの')
            && $body['text']['format']['name'] === 'material_extract';
    });
    Http::assertSent(fn (Request $request): bool => str_contains($request->data()['input'][1]['content'], 'Phase: finalize')
        && str_contains($request->data()['input'][2]['content'], '"id":"e1"')
        && $request->data()['text']['format']['name'] === 'material_finalize');

    // The screens show the evidence with its quotes and the angle.
    $this->get(route('materials.show', $material))->assertSee('NEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。')->assertSee('5 行')->assertSee('工業炉の脱炭素は、燃焼器の工学的課題に置き換わった')->assertSee('遷移を観測');
    $this->get(route('materials.index'))->assertSee('極めて困難 → 技術的に可能');
});

// A quote that is not in the lines it points at, or a reference to nothing, fails the checks; the agent is given the errors and gets one more go.
it('checks the quotes and the references, and repairs once', function () {
    $wrong = materialExtract(['primary_spans' => [
        ['id' => 'p1', 'line_start' => 5, 'line_end' => 5, 'quote' => 'NEDO は水素燃焼器の開発事業を開始した。'],
        ['id' => 'p2', 'line_start' => 99, 'line_end' => 99, 'quote' => '予算は 20 億円を予定している。'],
    ]]);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(materialAnswer($wrong))
        ->push(materialAnswer(materialExtract()))
        ->push(materialAnswer(materialFinalize()))]);
    $document = Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]);

    expect(extractMaterial($document)->status)->toBe('extracted');

    // The repair names what was wrong.
    Http::assertSent(fn (Request $request): bool => str_contains($request->data()['input'][1]['content'], 'span p1: the quote is not found verbatim in lines 5-5')
        && str_contains($request->data()['input'][1]['content'], 'span p2: line range 99-99 is outside the document'));
    expect(Http::recorded())->toHaveCount(3);
});

// A repair that still fails leaves the material failed, with the checks' report kept.
it('fails with the report when the checks do not pass twice', function () {
    $broken = materialFinalize(['claims' => [claim('c1', 'inference', 'x', 'inferred', ['nope'])]]);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(materialAnswer(materialExtract()))
        ->push(materialAnswer($broken))
        ->push(materialAnswer($broken))]);
    $document = Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]);

    $material = extractMaterial($document);

    expect($material->status)->toBe('failed')
        ->and($material->status_message)->toContain('分析が検査を通りませんでした')
        ->and($material->validation)->toContain('claim c1: basis nope is not a claim')
        ->and($material->data)->toBeNull();
    $this->get(route('materials.show', $material))->assertSee('直近の抽出が通らなかった検査');
});

// The checks in their own right: what the schema cannot say.
it('refuses an analysis that is not rooted in the evidence', function () {
    $validate = app(ValidateMaterial::class);
    $extract = materialExtract();

    expect($validate->finalize(materialFinalize(), $extract))->toBe([]);

    // An observed transition whose assessment claim rests on nothing primary.
    $floating = materialFinalize(['claims' => [claim('c1', 'inference', 'x', 'inferred', []), claim('c3', 'inference', 'y', 'inferred', ['c1'])]]);
    expect($validate->finalize($floating, $extract))->toContain('technology_transition: an observed transition needs an assessment claim rooted in primary evidence');

    // A circle between inferences.
    $circular = materialFinalize(['claims' => [claim('c1', 'inference', 'x', 'inferred', ['c2']), claim('c2', 'inference', 'y', 'inferred', ['c1']), claim('c3', 'inference', 'z', 'inferred', ['e1'])]]);
    expect($validate->finalize($circular, $extract))->toContain('claim c1: circular basis');

    // A recommended angle that is not a candidate.
    $held = materialFinalize();
    $held['editorial']['possible_angles'][0]['decision'] = 'hold';
    expect($validate->finalize($held, $extract))->toContain('recommended_angle_id a1 is not a candidate angle');

    // An angle without a tension, and one whose why-now is an inference rather than evidence.
    $loose = materialFinalize();
    $loose['editorial']['possible_angles'][0]['tension_ids'] = [];
    $loose['editorial']['possible_angles'][0]['why_now_primary_claim_ids'] = ['c1'];
    expect($validate->finalize($loose, $extract))->toContain('angle a1: needs at least one tension')->toContain('angle a1: c1 is not a primary_evidence claim');
});

it('does not ask the agent about a document that has not been fetched', function () {
    $material = extractMaterial(Document::factory()->create());

    expect($material->status)->toBe('failed')->and($material->status_message)->toContain('取得されていません');
    Http::assertNothingSent();
});

// The structuring layer and its model are set on 編集方針; a changed prompt is a new version.
it('reads the structuring layer and its model from the editorial policy screen', function () {
    EditorialPolicy::query()->delete();
    expect(EditorialPolicy::bodyFor('structuring'))->toContain('Editorial Research Analyst')
        ->and(EditorialPolicy::modelFor('structuring'))->toBe(EditorialPolicy::DEFAULT_MODEL);

    Livewire::test('pages::editorial-policy.index')
        ->set('structuring', 'あなたは分析者である。')->set('structuringModel', 'gpt-6-astra')
        ->call('save')->assertHasNoErrors();

    expect(EditorialPolicy::bodyFor('structuring'))->toBe('あなたは分析者である。')
        ->and(EditorialPolicy::modelFor('structuring'))->toBe('gpt-6-astra');

    Http::fake(['api.openai.com/v1/responses' => Http::sequence()
        ->push(materialAnswer(materialExtract()))
        ->push(materialAnswer(materialFinalize()))]);
    $material = extractMaterial(Document::factory()->fetched()->create(['markdown' => MATERIAL_MARKDOWN]));
    expect($material->status)->toBe('extracted')->and($material->model)->toBe('gpt-6-astra')->and($material->prompt?->text)->toBe('あなたは分析者である。');
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
