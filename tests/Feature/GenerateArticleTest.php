<?php

use App\Actions\ProposeArticle;
use App\Jobs\GenerateArticle;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const ARTICLE_POLICY = "素材情報から記事を書く。\n\n- 形式: Markdown\n- 長さ: 600 字程度\n";

const ARTICLE_ANSWER = ['title' => 'NEDO、アンモニア燃焼器の開発事業を開始', 'body' => "## 発表の概要\n\nNEDO は…\n\n## 出典\n\nhttps://www.nedo.go.jp/news/press/1.html"];

/**
 * What the agent would answer, as the OpenAI chat completion wire format.
 */
function articleAgentAnswer(mixed $content): array
{
    return ['choices' => [['message' => ['content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE)]]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4o-mini']);
    EditorialPolicy::query()->create(['layer' => 'article', 'body' => ARTICLE_POLICY]);
    $this->actingAs(User::factory()->create());
});

function generateArticle(Material $material): Article
{
    $article = Article::query()->updateOrCreate(['material_id' => $material->id], ['status' => 'generating']);
    (new GenerateArticle($article))->handle(app(ProposeArticle::class));

    return $article->refresh();
}

it('has the agent write an article from an extracted material per the article generation layer', function () {
    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer(ARTICLE_ANSWER))]);
    $material = Material::factory()->create(['data' => ['要約' => 'アンモニア燃焼器の開発事業を開始。', '発表主体' => 'NEDO']]);
    $material->updateEntry->update(['title' => 'アンモニア燃焼器', 'url' => 'https://www.nedo.go.jp/news/press/1.html']);

    $article = generateArticle($material);

    expect($article->status)->toBe('draft')
        ->and($article->title)->toBe(ARTICLE_ANSWER['title'])
        ->and($article->body)->toBe(ARTICLE_ANSWER['body'])
        ->and($article->status_message)->toContain('gpt-4o-mini');
    // The policy is the prompt; the material JSON, document title and URL are the input; the answer must be JSON.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com')
        && $request['response_format']['type'] === 'json_object'
        && str_contains($request['messages'][0]['content'], '- 形式: Markdown')
        && str_contains($request['messages'][1]['content'], '"要約": "アンモニア燃焼器の開発事業を開始。"')
        && str_contains($request['messages'][1]['content'], 'アンモニア燃焼器')
        && str_contains($request['messages'][1]['content'], 'https://www.nedo.go.jp/news/press/1.html'));
});

it('fails when the agent leaves out the title or the body', function () {
    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer(['title' => 'x']))]);

    $article = generateArticle(Material::factory()->create());

    expect($article->status)->toBe('failed')
        ->and($article->status_message)->toContain('タイトルと本文')
        ->and($article->title)->toBeNull();
});

it('fails when the agent does not answer JSON', function () {
    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer('not json'))]);

    $article = generateArticle(Material::factory()->create());

    expect($article->status)->toBe('failed')->and($article->status_message)->toContain('JSON');
});

it('does not ask the agent about a material that has not been extracted', function () {
    $article = generateArticle(Material::factory()->create(['status' => 'failed', 'data' => null]));

    expect($article->status)->toBe('failed')->and($article->status_message)->toContain('抽出されていません');
    Http::assertNothingSent();
});

it('reads the article generation layer from the editorial policy screen, with a default until it is saved', function () {
    EditorialPolicy::query()->delete();
    expect(EditorialPolicy::bodyFor('article'))->toContain('- 形式:');

    Livewire::test('pages::editorial-policy.index')
        ->assertSet('article', EditorialPolicy::DEFAULTS['article'])
        ->set('article', '- 長さ: 300 字')
        ->call('save')->assertHasNoErrors();

    expect(EditorialPolicy::bodyFor('article'))->toBe('- 長さ: 300 字');

    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer(ARTICLE_ANSWER))]);
    expect(generateArticle(Material::factory()->create())->status)->toBe('draft');
    Http::assertSent(fn (Request $request): bool => str_contains($request['messages'][0]['content'], '- 長さ: 300 字'));
});

it('queues the missing and failed articles of extracted materials, and one article again, from the screens', function () {
    Queue::fake();
    $missing = Material::factory()->create();
    $failed = Material::factory()->create();
    Article::factory()->for($failed)->create(['status' => 'failed', 'title' => null, 'body' => null]);
    $generated = Material::factory()->create();
    Article::factory()->for($generated)->create();
    Material::factory()->create(['status' => 'extracting', 'data' => null]);

    Livewire::test('pages::articles.index')->call('generate');

    Queue::assertPushed(GenerateArticle::class, 2);
    expect($missing->articles()->sole()->status)->toBe('generating')
        ->and($failed->articles()->sole()->status)->toBe('generating')
        ->and($generated->articles()->sole()->status)->toBe('draft')
        ->and(Article::query()->count())->toBe(3);

    Livewire::test('pages::materials.show', ['material' => $generated])->call('generate');
    Livewire::test('pages::articles.show', ['article' => $generated->articles()->sole()])->call('generate');

    Queue::assertPushed(GenerateArticle::class, 4);
    expect($generated->articles()->sole()->status)->toBe('generating')
        ->and(Article::query()->count())->toBe(3);
});

// Until the generation has run, the screens call the article by its update entry's title.
it('shows the update title while the article is generating', function () {
    $article = Article::factory()->create(['status' => 'generating', 'title' => null, 'body' => null]);

    $this->get(route('articles.index'))->assertSee($article->material->updateEntry->title)->assertSee('生成中');
    $this->get(route('articles.show', $article))->assertSee($article->material->updateEntry->title)->assertSee('まだ生成していません');
});
