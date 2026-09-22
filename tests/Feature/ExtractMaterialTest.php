<?php

use App\Actions\ProposeMaterial;
use App\Jobs\ExtractMaterial;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const MATERIAL_POLICY = "文書を次の観点で整理する。\n\n- 要約: 3 文以内\n- 発表主体: 組織名\n- 重要な事実: 数値や日付の一覧\n";

const MATERIAL_ANSWER = ['要約' => 'アンモニア燃焼器の開発事業を開始。', '発表主体' => 'NEDO', '重要な事実' => ['予算 20 億円', '2026〜2029 年度']];

/**
 * What the agent would answer, as the OpenAI chat completion wire format.
 */
function materialAgentAnswer(mixed $content): array
{
    return ['choices' => [['message' => ['content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE)]]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4o-mini']);
    EditorialPolicy::query()->create(['layer' => 'structuring', 'body' => MATERIAL_POLICY]);
    $this->actingAs(User::factory()->create());
});

function extractMaterial(Document $entry): Material
{
    $material = Material::query()->updateOrCreate(['document_id' => $entry->id], ['status' => 'extracting']);
    (new ExtractMaterial($material))->handle(app(ProposeMaterial::class));

    return $material->refresh();
}

it('has the agent structure a fetched document per the structuring layer and keeps the JSON', function () {
    Http::fake(['api.openai.com/*' => Http::response(materialAgentAnswer(MATERIAL_ANSWER))]);
    $entry = Document::factory()->fetched()->create(['markdown' => "# アンモニア燃焼器\n\nNEDO は…", 'url' => 'https://www.nedo.go.jp/news/press/1.html']);

    $material = extractMaterial($entry);

    expect($material->status)->toBe('extracted')
        ->and($material->data)->toEqual(MATERIAL_ANSWER)
        ->and($material->status_message)->toContain('gpt-4o-mini');
    // The policy is the prompt; the Markdown and URL are the input; the answer must be JSON.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com')
        && $request['response_format']['type'] === 'json_object'
        && str_contains($request['messages'][0]['content'], '- 要約: 3 文以内')
        && str_contains($request['messages'][1]['content'], '# アンモニア燃焼器')
        && str_contains($request['messages'][1]['content'], 'https://www.nedo.go.jp/news/press/1.html'));
});

it('fails when the agent leaves out an item the policy lists', function () {
    Http::fake(['api.openai.com/*' => Http::response(materialAgentAnswer(['要約' => 'x']))]);

    $material = extractMaterial(Document::factory()->fetched()->create());

    expect($material->status)->toBe('failed')
        ->and($material->status_message)->toContain('発表主体, 重要な事実')
        ->and($material->data)->toBeNull();
});

it('fails when the agent does not answer JSON', function () {
    Http::fake(['api.openai.com/*' => Http::response(materialAgentAnswer('not json'))]);

    $material = extractMaterial(Document::factory()->fetched()->create());

    expect($material->status)->toBe('failed')->and($material->status_message)->toContain('JSON');
});

it('does not ask the agent about a document that has not been fetched', function () {
    $material = extractMaterial(Document::factory()->create(['status' => 'failed']));

    expect($material->status)->toBe('failed')->and($material->status_message)->toContain('取得されていません');
    Http::assertNothingSent();
});

it('reads the structuring layer from the editorial policy screen, with a default until it is saved', function () {
    EditorialPolicy::query()->delete();
    expect(EditorialPolicy::bodyFor('structuring'))->toContain('- 要約:')
        ->and(EditorialPolicy::items(EditorialPolicy::bodyFor('structuring')))->toBe(['要約', '発表主体', '発表の種類', '技術領域', '重要な事実', '関係者', '意義', '背景']);

    Livewire::test('pages::editorial-policy.index')
        ->set('structuring', "- 要約: 短く\n- 技術領域: 分野")
        ->call('save')->assertHasNoErrors();

    expect(EditorialPolicy::bodyFor('structuring'))->toBe("- 要約: 短く\n- 技術領域: 分野")
        ->and(EditorialPolicy::items(EditorialPolicy::bodyFor('structuring')))->toBe(['要約', '技術領域']);

    Http::fake(['api.openai.com/*' => Http::response(materialAgentAnswer(['要約' => 'x', '技術領域' => ['y']]))]);
    expect(extractMaterial(Document::factory()->fetched()->create())->status)->toBe('extracted');
});

// jsonb hands the keys back sorted by length and letter; the screens show them in the policy's order.
it('shows the items in the order the policy lists them', function () {
    $material = Material::factory()->create(['data' => ['重要な事実' => ['a'], '要約' => 'x', 'メモ' => 'extra', '発表主体' => 'NEDO']]);

    expect(array_keys($material->refresh()->data))->not->toBe(['要約', '発表主体', '重要な事実', 'メモ'])
        ->and(array_keys((array) $material->dataInPolicyOrder()))->toBe(['要約', '発表主体', '重要な事実', 'メモ']);
    $this->get(route('materials.show', $material))->assertSeeInOrder(['"要約"', '"発表主体"', '"重要な事実"', '"メモ"']);
});

it('queues the missing and failed materials of fetched documents, and one material again, from the screens', function () {
    Queue::fake();
    $missing = Document::factory()->fetched()->create();
    $failed = Document::factory()->fetched()->create();
    Material::factory()->for($failed)->create(['status' => 'failed', 'data' => null]);
    $extracted = Document::factory()->fetched()->create();
    Material::factory()->for($extracted)->create();
    Document::factory()->create(['status' => 'fetching']);

    Livewire::test('pages::materials.index')->call('extract');

    Queue::assertPushed(ExtractMaterial::class, 2);
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
