<?php

use App\Actions\ProposeArticle;
use App\Actions\ProposeTranslation;
use App\Jobs\GenerateArticle;
use App\Jobs\TranslateArticle;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\Prompt;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const ARTICLE_POLICY = "素材情報から記事を書く。\n\n- 形式: Markdown\n- 長さ: 600 字程度\n";

const TRANSLATION_POLICY = "記事を対象言語へ翻訳する。一次情報と素材情報は文脈として使う。\n";

const ARTICLE_ANSWER = ['title' => 'NEDO、アンモニア燃焼器の開発事業を開始', 'body' => "## 発表の概要\n\nNEDO は…\n\n## 出典\n\nhttps://www.nedo.go.jp/news/press/1.html", 'language' => 'ja', 'topic_word' => 'アンモニア燃焼', 'title_draft' => 'NEDO がアンモニア燃焼器の開発事業を始めた', 'assumption' => '工業炉の熱は化石燃料で作るものだ'];

/**
 * What the agent would answer, as the Responses API wire format.
 */
function articleAgentAnswer(mixed $content): array
{
    return [
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE)]]]],
        'usage' => ['input_tokens' => 3000, 'input_tokens_details' => ['cached_tokens' => 2000, 'cache_write_tokens' => 0], 'output_tokens' => 800],
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.openai.key' => 'test-key']);
    Queue::fake();
    EditorialPolicy::query()->create(['layer' => 'article', 'body' => ARTICLE_POLICY, 'model' => 'gpt-5.6-luna']);
    EditorialPolicy::query()->create(['layer' => 'translation', 'body' => TRANSLATION_POLICY, 'model' => 'gpt-5.6-luna']);
    $this->actingAs(User::factory()->create());
});

function generateArticle(Material $material): Article
{
    // Queued as the screens queue it, so the article pins the prompt version and the model.
    $article = GenerateArticle::queueFor($material);
    (new GenerateArticle($article))->handle(app(ProposeArticle::class));

    return $article->refresh();
}

it('has the agent write an article from an extracted material per the article generation layer', function () {
    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer(ARTICLE_ANSWER))]);
    $material = Material::factory()->create(['data' => ['要約' => 'アンモニア燃焼器の開発事業を開始。', '発表主体' => 'NEDO']]);
    $material->document->update(['title' => 'アンモニア燃焼器', 'url' => 'https://www.nedo.go.jp/news/press/1.html']);

    $article = generateArticle($material);

    expect($article->status)->toBe('draft')
        ->and($article->title)->toBe(ARTICLE_ANSWER['title'])
        ->and($article->body)->toBe(ARTICLE_ANSWER['body'])
        ->and($article->status_message)->toContain('gpt-5.6-luna')
        // The version of the policy and the model it ran on are pinned, with what the call used.
        ->and($article->prompt->version)->toBe(1)
        ->and($article->model)->toBe('gpt-5.6-luna')
        // The article is the original, in the language the agent says it wrote, and the languages we publish in are queued after it.
        ->and($article->language)->toBe('ja')
        ->and($article->translated_from_id)->toBeNull()
        ->and($article->translationLanguages())->toBe(['en', 'zh-Hant', 'zh-Hans'])
        ->and($article)->toMatchArray(['input_tokens' => 3000, 'cached_tokens' => 2000, 'output_tokens' => 800]);
    Queue::assertPushed(TranslateArticle::class, 3);
    expect($material->articles()->whereNotNull('translated_from_id')->pluck('language')->sort()->values()->all())->toBe(['en', 'zh-Hans', 'zh-Hant']);
    // The policy is the cached developer message; the material JSON, document title and URL are the input; the answer is a title and a body.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/responses')
        && $request['model'] === 'gpt-5.6-luna'
        && $request['input'][0]['content'][0]['prompt_cache_breakpoint']['mode'] === 'explicit'
        && str_contains($request['input'][0]['content'][0]['text'], '- 形式: Markdown')
        // The title is reached in three steps, in the order the schema names them.
        && $request['text']['format']['schema']['required'] === ['topic_word', 'title_draft', 'assumption', 'title', 'body', 'language']
        && str_contains($request['input'][2]['content'], '"要約": "アンモニア燃焼器の開発事業を開始。"')
        && str_contains($request['input'][2]['content'], 'アンモニア燃焼器')
        && str_contains($request['input'][2]['content'], 'https://www.nedo.go.jp/news/press/1.html'));
});

// The other languages are translations of the article, not the same piece written again: the article goes in, the source and the material follow as context for the terms.
it('translates the article into the languages we publish in, with the source as context', function () {
    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer(['title' => 'NEDO starts an ammonia burner programme', 'body' => "## What happened\n\nNEDO …"]))]);
    $material = Material::factory()->create(['data' => ['angle' => 'アンモニアは使えない燃料ではなくなった', 'facts' => ['予算は 20 億円']]]);
    $material->document->update(['title' => 'アンモニア燃焼器', 'url' => 'https://www.nedo.go.jp/news/press/1.html']);
    $original = Article::factory()->for($material)->create(['language' => 'ja', 'title' => 'NEDO、アンモニア燃焼器の開発事業を開始', 'body' => '## 発表の概要\n\nNEDO は…']);

    $translation = TranslateArticle::queueFor($original, 'en');
    (new TranslateArticle($translation))->handle(app(ProposeTranslation::class));
    $translation->refresh();

    expect($translation->status)->toBe('draft')
        ->and($translation->language)->toBe('en')
        ->and($translation->translated_from_id)->toBe($original->id)
        ->and($translation->title)->toBe('NEDO starts an ammonia burner programme')
        ->and($translation->status_message)->toContain('翻訳しました')
        ->and($translation->prompt->name)->toBe('translation');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/responses')
            && $body['input'][0]['content'][0]['text'] === TRANSLATION_POLICY
            && isset($body['input'][0]['content'][0]['prompt_cache_breakpoint'])
            && str_contains($body['input'][1]['content'], 'English (en)')
            // The article is what is translated; the source and the material are context after it.
            && str_contains($body['input'][2]['content'], 'NEDO、アンモニア燃焼器の開発事業を開始')
            && str_contains($body['input'][2]['content'], 'Context, not to be translated in place of the article.')
            && str_contains($body['input'][2]['content'], 'https://www.nedo.go.jp/news/press/1.html')
            && str_contains($body['input'][2]['content'], 'アンモニアは使えない燃料ではなくなった');
    });

    // The article and its translations are one page, read by language.
    $this->get(route('articles.show', $original))->assertSee('日本語')->assertSee('English')->assertSee('原文');
    $this->get(route('articles.index'))->assertSee('日本語 / English');
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

it('reads the article generation layer from the articles screen, with a default until it is saved', function () {
    EditorialPolicy::query()->delete();
    expect(EditorialPolicy::bodyFor('article'))->toContain('- Format: Markdown');

    Livewire::test('pages::articles.index')
        ->assertSet('article', EditorialPolicy::DEFAULTS['article'])
        ->set('article', '- 長さ: 300 字')
        ->call('savePolicy')->assertHasNoErrors();

    expect(EditorialPolicy::bodyFor('article'))->toBe('- 長さ: 300 字');

    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer(ARTICLE_ANSWER))]);
    expect(generateArticle(Material::factory()->create())->status)->toBe('draft');
    Http::assertSent(fn (Request $request): bool => str_contains($request['input'][0]['content'][0]['text'], '- 長さ: 300 字'));
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

    $this->get(route('articles.index'))->assertSee($article->material->document->title)->assertSee('生成中');
    $this->get(route('articles.show', $article))->assertSee($article->material->document->title)->assertSee('まだ生成していません');
});
