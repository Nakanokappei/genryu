<?php

use App\Actions\ProposeArticle;
use App\Actions\ProposeTranslation;
use App\Actions\ValidateArticle;
use App\Jobs\CheckQuality;
use App\Jobs\GenerateArticle;
use App\Jobs\RefineHeadline;
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

const ARTICLE_HEADLINE = '工業炉の炎はアンモニアでも燃える';

const ARTICLE_URL = 'https://www.nedo.go.jp/news/press/1.html';

/**
 * A body in the shape the policy asks for — the opening with no heading,
 * three ## sections and the sources; the lead comes apart from it — that
 * counts the given number of characters before its sources.
 */
function articleBody(int $characters = 900): string
{
    // 起の段落。承の見出し 承。転の見出し 転。結の見出し: 24 characters before the filler.
    return "起の段落。\n\n## 承の見出し\n\n承。\n\n## 転の見出し\n\n転。\n\n## 結の見出し\n\n".str_repeat('炉', $characters - 24)."\n\n## 出典\n\n[NEDO「アンモニア燃焼器」](".ARTICLE_URL.')';
}

define('ARTICLE_ANSWER', ['body' => articleBody(), 'lead' => 'リード。', 'language' => 'ja']);

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
    // Queued as the screens queue it, so the article pins the prompt version and the model; the headline loop that runs first has settled the headline.
    $article = GenerateArticle::queueFor($material);
    $article->update(['title' => ARTICLE_HEADLINE]);
    $material->document->update(['url' => ARTICLE_URL]);
    (new GenerateArticle($article))->handle(app(ProposeArticle::class), app(ValidateArticle::class));

    return $article->refresh();
}

it('has the agent write the body under the settled headline from an extracted material per the article generation layer', function () {
    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer(ARTICLE_ANSWER))]);
    $material = Material::factory()->create(['data' => ['要約' => 'アンモニア燃焼器の開発事業を開始。', '発表主体' => 'NEDO']]);
    $material->document->update(['title' => 'アンモニア燃焼器', 'url' => 'https://www.nedo.go.jp/news/press/1.html']);

    $article = generateArticle($material);

    expect($article->status)->toBe('draft')
        ->and($article->title)->toBe(ARTICLE_HEADLINE)
        // The lead the writer wrote after the body goes above it, the separator line between them.
        ->and($article->body)->toBe("リード。\n\n-----\n\n".ARTICLE_ANSWER['body'])
        ->and($article->leadAndBody())->toBe(['リード。', ARTICLE_ANSWER['body']])
        ->and($article->status_message)->toContain('gpt-5.6-luna')
        // The version of the policy and the model it ran on are pinned, with what the call used.
        ->and($article->prompt->version)->toBe(1)
        ->and($article->model)->toBe('gpt-5.6-luna')
        // The article is the original, in the language the agent says it wrote, and the languages we publish in are queued after it.
        ->and($article->language)->toBe('ja')
        ->and($article->translated_from_id)->toBeNull()
        ->and($article->translationLanguages())->toBe(['en', 'zh-Hant', 'zh-Hans'])
        ->and($article)->toMatchArray(['input_tokens' => 3000, 'cached_tokens' => 2000, 'output_tokens' => 800]);
    // The headline loop is queued first; the translations and the quality check (編成) follow the body.
    Queue::assertPushed(RefineHeadline::class, 1);
    Queue::assertPushed(TranslateArticle::class, 3);
    Queue::assertPushed(CheckQuality::class, 1);
    expect($article->headline_model)->toBe(EditorialPolicy::modelFor('headline'))
        ->and($article->headlinePrompt?->name)->toBe('headline');
    // The policy is the cached developer message; the headline, the material JSON, document title and URL are the input; the answer is a body.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/responses')
        && $request['model'] === 'gpt-5.6-luna'
        && $request['input'][0]['content'][0]['prompt_cache_breakpoint']['mode'] === 'explicit'
        && str_contains($request['input'][0]['content'][0]['text'], '- 形式: Markdown')
        && $request['text']['format']['schema']['required'] === ['body', 'lead', 'language', 'figures']
        && str_contains($request['input'][2]['content'], 'Headline: '.ARTICLE_HEADLINE)
        // A source without figures offers none to quote.
        && ! str_contains($request['input'][2]['content'], 'Figures of the source')
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
    $this->get(route('editorial.articles.show', $original))->assertSee('日本語')->assertSee('English')->assertSee('原文');
    $this->get(route('editorial.articles.index'))->assertSee('日本語 / English');
});

it('fails when the agent leaves out the body', function () {
    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer(['language' => 'ja']))]);

    $article = generateArticle(Material::factory()->create());

    expect($article->status)->toBe('failed')
        ->and($article->status_message)->toContain('本文を返しませんでした')
        ->and($article->body)->toBeNull();
});

it('does not write a body before the headline is settled', function () {
    $article = GenerateArticle::queueFor(Material::factory()->create());
    (new GenerateArticle($article))->handle(app(ProposeArticle::class), app(ValidateArticle::class));

    expect($article->refresh()->status)->toBe('failed')->and($article->status_message)->toContain('見出しがまだ');
    Http::assertNothingSent();
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

    Livewire::test('pages::editorial.articles.index')
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

    Livewire::test('pages::editorial.articles.index')->call('generate');

    // Each article begins with its headline; the body follows the loop.
    Queue::assertPushed(RefineHeadline::class, 2);
    expect($missing->articles()->sole()->status)->toBe('generating')
        ->and($failed->articles()->sole()->status)->toBe('generating')
        ->and($generated->articles()->sole()->status)->toBe('draft')
        ->and(Article::query()->count())->toBe(3);

    Livewire::test('pages::editorial.materials.show', ['material' => $generated])->call('generate');
    Livewire::test('pages::editorial.articles.show', ['article' => $generated->articles()->sole()])->call('generate');

    Queue::assertPushed(RefineHeadline::class, 4);
    expect($generated->articles()->sole()->status)->toBe('generating')
        ->and(Article::query()->count())->toBe(3);
});

// Until the generation has run, the screens call the article by its update entry's title.
it('shows the update title while the article is generating', function () {
    $article = Article::factory()->create(['status' => 'generating', 'title' => null, 'body' => null]);

    $this->get(route('editorial.articles.index'))->assertSee($article->material->document->title)->assertSee('生成中');
    $this->get(route('editorial.articles.show', $article))->assertSee($article->material->document->title)->assertSee('まだ生成していません');
});

// The length is counted in code: the sources and the Markdown marks are left out, characters for Chinese or Japanese, words otherwise.
it('counts the length of a body as the policy does', function () {
    expect(ValidateArticle::lengthOf(articleBody(), 'ja'))->toMatchArray(['count' => 900, 'unit' => 'characters', 'off' => 0])
        ->and(ValidateArticle::lengthOf(articleBody(1300), 'ja')['off'])->toBe(100)
        ->and(ValidateArticle::lengthOf(articleBody(700), null)['off'])->toBe(-100)
        ->and(ValidateArticle::lengthOf("## Why\n\n".str_repeat('word ', 600)."\n\n## Sources\n\n[NEDO](https://example.com)", 'en'))->toMatchArray(['count' => 601, 'unit' => 'words', 'off' => 0]);
});

// A body outside the range is written once more with its count in hand, and the one nearer the range is kept.
it('writes a body again when it is too long and keeps the nearer one', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(articleAgentAnswer(['body' => articleBody(1400), 'lead' => 'リード。', 'language' => 'ja']))
        ->push(articleAgentAnswer(['body' => articleBody(1100), 'lead' => 'リード。', 'language' => 'ja']))]);

    $article = generateArticle(Material::factory()->create());

    expect($article->status)->toBe('draft')
        ->and(ValidateArticle::lengthOf($article->leadAndBody()[1], 'ja')['count'])->toBe(1100)
        ->and($article->status_message)->not->toContain('検査を通りませんでした')
        // Both calls are paid for, so both are counted.
        ->and($article->input_tokens)->toBe(6000);
    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['input'][3]['content'] ?? ''), 'It is 1400 characters long'));
});

// A rewrite that is no nearer is dropped, and the count is shown rather than anything waiting on a person.
it('keeps the first body when no rewrite is nearer, and says so', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(articleAgentAnswer(['body' => articleBody(1300), 'lead' => 'リード。', 'language' => 'ja']))
        ->push(articleAgentAnswer(['body' => articleBody(1500), 'lead' => 'リード。', 'language' => 'ja']))
        ->push(articleAgentAnswer(['body' => articleBody(1400), 'lead' => 'リード。', 'language' => 'ja']))]);

    $article = generateArticle(Material::factory()->create());

    expect($article->status)->toBe('draft')
        ->and(ValidateArticle::lengthOf($article->leadAndBody()[1], 'ja')['count'])->toBe(1300)
        ->and($article->status_message)->toContain('検査を通りませんでした')->toContain('It is 1300 characters long');
    // The first write and both rewrites.
    expect(Http::recorded())->toHaveCount(1 + GenerateArticle::MAX_REWRITES);
    Queue::assertPushed(TranslateArticle::class, 3);
});

// Models write paragraphs one newline apart, which Markdown would run together; every line is set apart before the body is kept.
it('sets every line of a body apart as its own block', function () {
    expect(Article::separateBlocks("リード。\n起の段落。\n## 承\n承の段落。\n\n\n## 出典\n[NEDO](https://example.com)\n"))
        ->toBe("リード。\n\n起の段落。\n\n## 承\n\n承の段落。\n\n## 出典\n\n[NEDO](https://example.com)");
});

// The shape is counted, not judged: each way a body can come apart is found, one line the agent can act on.
it('finds where the shape of a body comes apart', function () {
    $problems = fn (string $body): array => ValidateArticle::shapeProblems($body, ARTICLE_HEADLINE, ARTICLE_URL);

    expect($problems(articleBody()))->toBe([])
        // Opens on a heading, so the lead is gone.
        ->and($problems("## 始まり\n\n".articleBody()))->toContain('The body starts with a heading; it must start with the opening, with no heading.')
        // The opening swallowed 承: only two sections before the sources.
        ->and(implode(' ', $problems(str_replace("## 承の見出し\n\n", '', articleBody()))))->toContain('exactly 3 ## headings')
        // A heading that is only the name of its part, a heading other than ##, and a section with nothing under it.
        ->and(implode(' ', $problems(str_replace('## 転の見出し', '## 転', articleBody()))))->toContain('is a label')
        ->and(implode(' ', $problems(str_replace('## 結の見出し', '### 結の見出し', articleBody()))))->toContain('Only ## headings')
        ->and(implode(' ', $problems(str_replace("承。\n\n", '', articleBody()))))->toContain('has no text under it')
        // The sources: missing, or not linking to the primary source.
        ->and(implode(' ', $problems(preg_replace('/\n\n## 出典.*\z/s', '', articleBody()))))->toContain('must end with a ## 出典 section')
        ->and(implode(' ', $problems(str_replace(ARTICLE_URL, 'https://example.com', articleBody()))))->toContain('must link to the primary source');
});

// A body that came apart is written again with its problems in hand, and the one that holds together is kept.
it('writes a body again when its shape comes apart', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(articleAgentAnswer(['body' => "## 始まり\n\n".str_repeat('炉', 900)."\n\n## 出典\n\n[NEDO](".ARTICLE_URL.')', 'lead' => 'リード。', 'language' => 'ja']))
        ->push(articleAgentAnswer(ARTICLE_ANSWER))]);

    $article = generateArticle(Material::factory()->create());

    expect($article->leadAndBody()[1])->toBe(ARTICLE_ANSWER['body'])
        ->and($article->status_message)->not->toContain('検査を通りませんでした');
    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['input'][3]['content'] ?? ''), '- The body starts with a heading'));
});

// 図版: the writer quotes up to two figures of the source by number; the job keeps valid choices, with the URL from the material.
it('quotes the figures the writer chose, taking the figure from the material', function () {
    Http::fake(['api.openai.com/*' => Http::response(articleAgentAnswer([...ARTICLE_ANSWER, 'figures' => [
        ['figure' => 2, 'section' => 'technology'],
        ['figure' => 9, 'section' => 'opening'],
        ['figure' => 2, 'section' => 'outlook'],
        ['figure' => 1, 'section' => 'background'],
        ['figure' => 3, 'section' => 'outlook'],
    ]]))]);
    $material = Material::factory()->create(['data' => ['要約' => '燃焼器の開発。', 'figures' => [
        ['url' => 'https://www.nedo.go.jp/img/1.png', 'alt' => '概要図', 'caption' => null],
        ['url' => 'https://www.nedo.go.jp/img/2.png', 'alt' => '燃焼器', 'caption' => '図2 燃焼器の構造'],
        ['url' => 'https://www.nedo.go.jp/img/3.png', 'alt' => '', 'caption' => null],
    ]]]);

    $article = generateArticle($material);

    // A number the source has not, the same figure twice and a third figure are dropped.
    expect($article->figures)->toBe([
        ['url' => 'https://www.nedo.go.jp/img/2.png', 'alt' => '燃焼器', 'caption' => '図2 燃焼器の構造', 'section' => 'technology'],
        ['url' => 'https://www.nedo.go.jp/img/1.png', 'alt' => '概要図', 'caption' => null, 'section' => 'background'],
    ]);
    Http::assertSent(fn (Request $request): bool => str_contains((string) collect($request['input'])->last()['content'], "Figures of the source (quote by number):\n1. 概要図\n2. 燃焼器 図2 燃焼器の構造\n3. ")
        && $request['text']['format']['schema']['required'] === ['body', 'lead', 'language', 'figures']);
});

// A source with figures always has one in its article: the first, in the section on the new technology, when the writer chose none.
it('quotes the first figure when the writer chose none of a source that has figures', function () {
    $figures = [['url' => 'https://www.nedo.go.jp/img/1.png', 'alt' => '概要図', 'caption' => null], ['url' => 'https://www.nedo.go.jp/img/2.png', 'alt' => '', 'caption' => null]];

    expect(GenerateArticle::figures([], $figures))->toBe([['url' => 'https://www.nedo.go.jp/img/1.png', 'alt' => '概要図', 'caption' => null, 'section' => 'technology']])
        ->and(GenerateArticle::figures([['figure' => 7, 'section' => 'opening']], $figures)[0]['url'])->toBe('https://www.nedo.go.jp/img/1.png')
        ->and(GenerateArticle::figures([], []))->toBe([]);
});

// A figure is a quotation: the source's own URL, loaded by the reader's browser, in its section, framed, with the source named in the article's language.
it('sets the quoted figures into the body as quotations from the source', function () {
    $material = Material::factory()->create(['data' => ['要約' => '燃焼器の開発。']]);
    $material->document->update(['title' => 'アンモニア燃焼器', 'url' => ARTICLE_URL]);
    $original = Article::factory()->for($material)->create(['language' => 'ja', 'body' => articleBody(100), 'figures' => [
        ['url' => 'https://www.nedo.go.jp/img/2.png', 'alt' => '燃焼器', 'caption' => '図2 燃焼器の構造', 'section' => 'technology'],
    ]]);
    $translation = Article::factory()->for($material)->create(['language' => 'en', 'translated_from_id' => $original->id, 'body' => "Lead.\n\n## Background\n\nText.\n\n## The technology\n\nText.\n\n## Outlook\n\nText.\n\n## Sources\n\n[NEDO](".ARTICLE_URL.')']);

    $html = $original->bodyHtml();

    expect($html)->toContain('<img src="https://www.nedo.go.jp/img/2.png" alt="燃焼器" loading="lazy" decoding="async" referrerpolicy="no-referrer"')
        ->toContain('max-height:400px')->toContain('object-fit:contain')
        ->toContain('図2 燃焼器の構造 出典: <a href="'.ARTICLE_URL.'"')
        // In the new-technology section: after its text, before the outlook's heading.
        ->and(strpos($html, '<figure'))->toBeGreaterThan(strpos($html, '転。'))
        ->and(strpos($html, '<figure'))->toBeLessThan(strpos($html, '結の見出し'));
    // A translation quotes its original's figures, labelled in its own language.
    expect($translation->bodyHtml())->toContain('https://www.nedo.go.jp/img/2.png')->toContain('Source: <a href=')
        ->and(strpos($translation->bodyHtml(), '<figure'))->toBeLessThan(strpos($translation->bodyHtml(), 'Outlook'));
});

// Our Markdown's rule: the lead, a line of five hyphens, the body; a body without the line has no lead.
it('splits an article into its lead and its body at the separator line', function () {
    $article = Article::factory()->make(['body' => Article::withLead('リード。', "起の段落。\n\n## 承\n\n承。")]);

    expect($article->leadAndBody())->toBe(['リード。', "起の段落。\n\n## 承\n\n承。"])
        ->and(Article::factory()->make(['body' => "# 見出し\n\n起の段落。"])->leadAndBody())->toBe([null, '起の段落。'])
        ->and($article->bodyHtml())->toStartWith('<div data-lead><p>リード。</p>')->toContain('</div><div data-body><p>起の段落。</p>')->not->toContain('<hr');
});

// A lead left out is a problem like any other: the body is written again with it in hand.
it('writes the article again when the writer leaves out the lead', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(articleAgentAnswer(['body' => articleBody(), 'lead' => '', 'language' => 'ja']))
        ->push(articleAgentAnswer(ARTICLE_ANSWER))]);

    $article = generateArticle(Material::factory()->create());

    expect($article->leadAndBody()[0])->toBe('リード。');
    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['input'][3]['content'] ?? ''), 'The lead is missing'));
});

it('takes a sources heading with its translation after a slash', function () {
    $body = str_replace('## 出典', '## 出典 / Sources', articleBody());

    expect(ValidateArticle::shapeProblems($body, ARTICLE_HEADLINE, ARTICLE_URL))->toBe([]);
});
