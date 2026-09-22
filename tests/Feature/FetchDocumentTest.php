<?php

use App\Actions\FetchUpdates;
use App\Actions\ProposeDocumentSettings;
use App\Actions\ReadDocument;
use App\Jobs\FetchDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Source;
use App\Models\UpdateEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const DOCUMENT_BODY = '<p>The agency announced a programme to develop compact ammonia burners for industrial furnaces, cutting CO2 from heat.</p>'
    .'<h2>Background</h2><p>Ammonia carries hydrogen without the cost of liquefaction and can be burned directly.</p>'
    .'<ul><li>Budget: 2 billion yen</li><li>Period: 2026-2029</li></ul>'
    .'<p>See the <a href="/docs/plan.pdf">plan</a> and <img src="../img/burner.png" alt="burner"></p>';

const DOCUMENT_PAGE = '<html><head><meta charset="utf-8"><title>Site</title><script>var x = 1;</script></head><body>'
    .'<nav><a href="/">Home</a><a href="/news">News</a></nav>'
    .'<article><h1>Ammonia burner programme</h1><time datetime="2026-09-17">2026-09-17</time>'.DOCUMENT_BODY
    .'<div class="share"><a href="https://x.com/share">Share on X</a></div></article>'
    .'<footer>Copyright</footer></body></html>';

/**
 * What the agent would answer, as the OpenAI chat completion wire format.
 */
function documentAgentAnswer(array $selectors): array
{
    return ['choices' => [['message' => ['content' => json_encode($selectors)]]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*/robots.txt' => Http::response('', 404)]);
    Storage::fake('local');
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4o-mini']);
    $this->actingAs(User::factory()->create());
});

function fetchDocument(UpdateEntry $entry): Document
{
    $document = Document::query()->updateOrCreate(['update_entry_id' => $entry->id], ['title' => $entry->title, 'url' => $entry->url, 'status' => 'fetching']);
    (new FetchDocument($document))->handle(app(ReadDocument::class), app(ProposeDocumentSettings::class));

    return $document->refresh();
}

it('reads an HTML page into Markdown with the document settings of the source and keeps the original', function () {
    Http::fake(['www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);
    $source = Source::factory()->create(['document_config' => ['content' => 'article', 'remove' => '.share']]);
    $entry = UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1', 'title' => 'Ammonia burner programme']);

    $document = fetchDocument($entry);

    expect($document)->toMatchArray(['status' => 'fetched', 'format' => 'html', 'original_path' => "documents/{$source->id}/{$entry->id}.html", 'status_message' => null])
        ->and($document->fetched_at)->not->toBeNull()
        // Heading, date, body; the body's headings sit one level under the document heading.
        ->and($document->markdown)->toStartWith("# Ammonia burner programme\n\n2026-09-17\n\nThe agency announced")
        ->toContain('## Background')
        ->toContain('- Budget: 2 billion yen')
        // Links and images resolve against the page; navigation, scripts and the share bar are gone.
        ->toContain('[plan](https://www.example.org/docs/plan.pdf)')
        ->toContain('![burner](https://www.example.org/img/burner.png)')
        ->not->toContain('Home')->not->toContain('Share on X')->not->toContain('var x')->not->toContain('Copyright');
    Storage::disk('local')->assertExists("documents/{$source->id}/{$entry->id}.html");
    expect(Storage::disk('local')->get("documents/{$source->id}/{$entry->id}.html"))->toBe(DOCUMENT_PAGE);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

// DARPA: the <h1> sits in the page header outside the article, the date is a short <h5>, prizes are a table, contact is a mailto link.
it('titles the document from the update list or the page heading, dates it from a short date line, keeps tables and mailto links, and drops emptied headings', function () {
    $page = '<html><body><header><h1> $1M to advance   AI tools </h1></header><article>'
        .'<h2 class="share"><a href="/share">Share</a></h2><h5 class="news-date">June 26, 2026</h5>'
        .'<p>DARPA is launching a prize competition designed to rapidly advance AI-driven medical tools for point-of-injury care.</p>'
        .'<table><tr><td>1st place</td><td>$300,000</td></tr><tr><td>2nd place</td><td>$150,000</td></tr></table>'
        .'<p>Media should contact <a href="mailto:outreach@darpa.mil">outreach@darpa.mil</a>.</p>'
        .'</article></body></html>';
    Http::fake(['www.example.org/news/1' => Http::response($page, 200, ['Content-Type' => 'text/html'])]);
    $source = Source::factory()->create(['document_config' => ['content' => 'article', 'remove' => '.share a']]);

    $document = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1', 'title' => '$1M to advance AI tools']));

    expect($document->status)->toBe('fetched')
        ->and($document->markdown)->toStartWith("# \$1M to advance AI tools\n\n2026-06-26\n\nDARPA is launching")
        ->toContain("| 1st place | \$300,000 |\n|---|---|\n| 2nd place | \$150,000 |")
        ->toContain('<outreach@darpa.mil>')
        ->not->toContain('##');

    // Without any <h1> on the page, the update entry's title stands in.
    Http::fake(['www.example.org/news/3' => Http::response(str_replace('<header><h1> $1M to advance   AI tools </h1></header>', '', $page), 200, ['Content-Type' => 'text/html'])]);
    expect(fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/3', 'title' => 'Entry title']))->markdown)->toStartWith("# Entry title\n\n2026-06-26\n\n");

    // With a page <h1> and an entry title that differ, what the update list said wins (the page's <h1> is often the site or the section).
    Http::fake(['www.example.org/news/2' => Http::response($page, 200, ['Content-Type' => 'text/html'])]);
    $untitled = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/2', 'title' => 'Entry title']));
    expect($untitled->markdown)->toStartWith("# Entry title\n\n2026-06-26\n\n");
});

// A Japanese press release (三菱電機): a back link and a caution before the date, the title as an <h2>, indented markup,
// notes and a copyright line after the body, and the heading of a search widget whose form is gone.
it('reads title, date and body in that order, moves fixed text to the end, drops navigation and keeps heading levels relative under #', function () {
    $page = '<html><body><h1 class="logo"><img src="/logo.png" alt=""></h1><div class="breadcrumb"><a href="/">トップページ</a> &gt; <a href="/news">ニュース</a></div>'
        ."<article>\n  <a class=\"link--back\" href=\"/news\">最新ニュース一覧ページへ戻る</a>\n  <p class=\"caution\">掲載のデータは発表当時のものです。</p>\n"
        .'<p class="date">更新日：2026年9月10日</p><h2 class="title">アンモニア燃焼器の開発を開始</h2>'
        ."\n  <p>NEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。事業期間は 2026 年度から 2029 年度までの 4 年間である。<br>"
        .'  <br>予算は 20 億円を予定している。</p>'
        ."\n    <h3>背景</h3><p>アンモニアは液化の費用をかけずに水素を運べる。</p>"
        .'<h4>事業の位置づけ</h4><p>本事業はグリーンイノベーション基金の一部である。</p>'
        .'<p class="notice">掲載時の注意：本ページの情報は発表時点のものです。</p>'
        .'<p>※ 詳細は担当部署までお問い合わせください。</p>'
        .'<p>Copyright 2026 NEDO. All rights reserved.</p>'
        .'<h2>カテゴリーや発表年別で探す</h2><form><input name="q"></form></article></body></html>';
    Http::fake(['www.example.org/news/1' => Http::response($page, 200, ['Content-Type' => 'text/html'])]);
    $source = Source::factory()->create(['document_config' => ['content' => 'article', 'date' => '.date', 'remove' => '', 'fixed_text' => '.notice']]);

    $document = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1', 'title' => 'アンモニア燃焼器の開発を開始']));

    expect($document->status)->toBe('fetched')
        ->and($document->markdown)->toBe(
            "# アンモニア燃焼器の開発を開始\n\n"
            ."2026-09-10\n\n"
            ."NEDO は、工業炉向けの小型アンモニア燃焼器の開発事業を開始した。事業期間は 2026 年度から 2029 年度までの 4 年間である。\n\n"
            ."予算は 20 億円を予定している。\n\n"
            ."## 背景\n\n"
            ."アンモニアは液化の費用をかけずに水素を運べる。\n\n"
            ."### 事業の位置づけ\n\n"
            ."本事業はグリーンイノベーション基金の一部である。\n\n"
            ."---\n\n"
            ."掲載時の注意：本ページの情報は発表時点のものです。\n\n"
            ."掲載のデータは発表当時のものです。\n\n"
            ."※ 詳細は担当部署までお問い合わせください。\n\n"
            .'Copyright 2026 NEDO. All rights reserved.'
        );
});

// The selection layer: an entry whose title has an exclude keyword is listed as 対象外 and nothing is fetched for it.
it('does not fetch the document of an update entry whose title has an exclude keyword', function () {
    Queue::fake();
    EditorialPolicy::query()->create(['layer' => 'exclude_keywords', 'body' => '採用情報; セミナー ;']);
    $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
        .'<item><title>アンモニア燃焼器の開発を開始</title><link>https://www.example.org/news/1</link></item>'
        .'<item><title>水素セミナー開催のお知らせ</title><link>https://www.example.org/news/2</link></item></channel></rss>';
    Http::fake(['www.example.org/rss.xml' => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    app(FetchUpdates::class)($source);

    Queue::assertPushed(FetchDocument::class, 1);
    $excluded = UpdateEntry::query()->where('url', 'https://www.example.org/news/2')->sole();
    expect($excluded->excluded_by)->toBe('セミナー')->and($excluded->document)->toBeNull()
        ->and(UpdateEntry::query()->where('url', 'https://www.example.org/news/1')->sole()->excluded_by)->toBeNull();
    $this->get(route('updates.index'))->assertSee('対象外');
    $this->get(route('updates.show', $excluded))->assertSee('除外キーワード「セミナー」に一致');

    // The source's "fetch documents" leaves excluded entries alone; the entry's own button still fetches it.
    Livewire::test('pages::sources.show', ['source' => $source])->call('fetchDocuments');
    Queue::assertPushed(FetchDocument::class, 1);
    Livewire::test('pages::updates.show', ['updateEntry' => $excluded])->call('fetchDocument');
    Queue::assertPushed(FetchDocument::class, 2);
});

it('saves the selection layer from the updates screen', function () {
    Livewire::test('pages::updates.index')
        ->assertSet('excludeKeywords', '')
        ->set('excludeKeywords', '採用情報; セミナー')->set('fetchCriteria', '技術的な発表')->set('skipCriteria', '人事')
        ->call('saveSelection')->assertHasNoErrors();

    expect(EditorialPolicy::excludeKeywords())->toBe(['採用情報', 'セミナー'])
        ->and(EditorialPolicy::bodyFor('fetch_criteria'))->toBe('技術的な発表')
        ->and(EditorialPolicy::bodyFor('skip_criteria'))->toBe('人事');
    $this->get(route('editorial-policy'))->assertSee('取捨選択は「更新リスト」の画面で設定します。');
});

it('asks the agent for document settings when the source has none, verifies them on the page, and saves them for the next documents', function () {
    Http::fake([
        'www.example.org/news/*' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'article', 'date' => 'time', 'remove' => '.share', 'fixed_text' => ''])),
    ]);
    $source = Source::factory()->create();
    $first = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($first->status)->toBe('fetched')
        ->and($first->status_message)->toContain('エージェントが提案した文書の設定')
        ->and($first->markdown)->not->toContain('Share on X')
        ->and($source->refresh()->document_config)->toEqual(['content' => 'article', 'date' => 'time', 'remove' => '.share', 'fixed_text' => '']);
    // The agent receives the page, without scripts, and must answer JSON.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com')
        && $request['response_format']['type'] === 'json_object'
        && str_contains($request['messages'][1]['content'], '<article>')
        && ! str_contains($request['messages'][1]['content'], 'var x'));

    // The second document of the source is read with the saved settings: no second agent call.
    $second = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/2']));
    expect($second->status)->toBe('fetched')->and($second->status_message)->toBeNull();
    Http::assertSentCount(4); // robots.txt, page 1, agent, page 2
});

it('falls back to generic selectors when the proposed content selector finds nothing, and saves what worked', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'div.press-body', 'remove' => ''])),
    ]);
    $source = Source::factory()->create();

    $document = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('fetched')
        ->and($source->refresh()->document_config)->toMatchArray(['content' => 'article', 'remove' => '']);
});

// The site changed its layout: the saved settings match nothing, so the agent is asked again.
it('asks the agent again when the saved document settings no longer match the page', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'article', 'remove' => ''])),
    ]);
    $source = Source::factory()->create(['document_config' => ['content' => 'div.old-layout', 'remove' => '']]);

    $document = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('fetched')
        ->and($source->refresh()->document_config['content'])->toBe('article');
});

it('fails with a clear message when neither the proposal nor the generic selectors find a body', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response('<html><body><div class="x"><p>Short.</p></div></body></html>', 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'div.x', 'remove' => ''])),
    ]);
    $source = Source::factory()->create();

    $document = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('failed')
        ->and($document->status_message)->toContain('本文を見つけられませんでした')
        ->and($source->refresh()->document_config)->toBeNull();
});

it('reads a PDF as text and keeps the original', function () {
    $pdf = (string) file_get_contents(base_path('tests/Fixtures/press-release.pdf'));
    Http::fake(['www.example.org/press/1.pdf' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])]);
    $source = Source::factory()->create();
    $entry = UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/press/1.pdf']);

    $document = fetchDocument($entry);

    expect($document)->toMatchArray(['status' => 'fetched', 'format' => 'pdf', 'original_path' => "documents/{$source->id}/{$entry->id}.pdf"])
        ->and($document->markdown)->toBe("Hello from a PDF press release.\nSecond line of the release.");
    Storage::disk('local')->assertExists("documents/{$source->id}/{$entry->id}.pdf");
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

it('records a failure instead of throwing when the page cannot be fetched', function () {
    Http::fake(['www.example.org/*' => Http::response('gone', 500)]);

    $document = fetchDocument(UpdateEntry::factory()->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('failed')->and($document->status_message)->toContain('500');
});

it('queues a fetch for every new update entry, and only for new ones', function () {
    Queue::fake();
    $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
        .'<item><title>One</title><link>https://www.example.org/news/1</link></item>'
        .'<item><title>Two</title><link>https://www.example.org/news/2</link></item></channel></rss>';
    Http::fake(['www.example.org/rss.xml' => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    app(FetchUpdates::class)($source);
    app(FetchUpdates::class)($source);

    Queue::assertPushed(FetchDocument::class, 2);
    expect(Document::query()->where('status', 'fetching')->count())->toBe(2)
        ->and(Document::query()->where('url', 'https://www.example.org/news/1')->sole()->title)->toBe('One');
});

it('queues the missing and failed documents of a source, and one document again, from the screens', function () {
    Queue::fake();
    $source = Source::factory()->create();
    $missing = UpdateEntry::factory()->for($source)->create();
    $failed = UpdateEntry::factory()->for($source)->create();
    Document::factory()->for($failed)->create(['status' => 'failed']);
    $fetched = UpdateEntry::factory()->for($source)->create();
    Document::factory()->for($fetched)->create();

    Livewire::test('pages::sources.show', ['source' => $source])->call('fetchDocuments');

    Queue::assertPushed(FetchDocument::class, 2);
    expect($missing->document?->status)->toBe('fetching')
        ->and($failed->document()->sole()->status)->toBe('fetching')
        ->and($fetched->document()->sole()->status)->toBe('fetched');

    Livewire::test('pages::updates.show', ['updateEntry' => $fetched])->call('fetchDocument');
    Livewire::test('pages::documents.show', ['document' => $fetched->document()->sole()])->call('fetch');

    Queue::assertPushed(FetchDocument::class, 4);
    expect($fetched->document()->sole()->status)->toBe('fetching')
        ->and(Document::query()->count())->toBe(3);
});

it('saves the document settings from the source detail screen', function () {
    $source = Source::factory()->create();

    Livewire::test('pages::sources.show', ['source' => $source])
        ->set('documentSettings.content', 'article')->set('documentSettings.remove', '.share')->set('documentSettings.fixed_text', '.notice')
        ->call('saveDocumentSettings')->assertHasNoErrors();
    expect($source->refresh()->document_config)->toEqual(['content' => 'article', 'date' => '', 'remove' => '.share', 'fixed_text' => '.notice']);

    // Clearing the content selector hands the settings back to the agent.
    Livewire::test('pages::sources.show', ['source' => $source])
        ->assertSet('documentSettings.content', 'article')
        ->set('documentSettings.content', '')
        ->call('saveDocumentSettings')->assertHasNoErrors();
    expect($source->refresh()->document_config)->toBeNull();
});

it('serves the original file', function () {
    Storage::disk('local')->put('documents/1/1.html', DOCUMENT_PAGE);
    $document = Document::factory()->create(['original_path' => 'documents/1/1.html']);

    $this->get(route('documents.original', $document))->assertOk()->assertDownload('1.html');
});
