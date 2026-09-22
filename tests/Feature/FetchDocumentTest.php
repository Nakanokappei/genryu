<?php

use App\Actions\FetchFavicon;
use App\Actions\FetchUpdates;
use App\Actions\ProposeDocumentSettings;
use App\Actions\ReadDocument;
use App\Jobs\FetchDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Source;
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
    // Sites without an icon: the favicon fetch that comes with the first page of a source finds nothing.
    Http::fake(['*/robots.txt' => Http::response('', 404), '*/favicon.ico' => Http::response('', 404)]);
    Storage::fake('local');
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4o-mini']);
    $this->actingAs(User::factory()->create());
});

function fetchDocument(Document $entry): Document
{
    $entry->update(['status' => 'fetching']);
    (new FetchDocument($entry))->handle(app(ReadDocument::class), app(ProposeDocumentSettings::class), app(FetchFavicon::class));

    return $entry->refresh();
}

it('reads an HTML page into Markdown with the document settings of the source and keeps the original', function () {
    Http::fake(['www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);
    $source = Source::factory()->create(['document_config' => ['content' => 'article', 'remove' => '.share']]);
    $entry = Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/1', 'title' => 'Ammonia burner programme']);

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

    $document = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/1', 'title' => '$1M to advance AI tools']));

    expect($document->status)->toBe('fetched')
        ->and($document->markdown)->toStartWith("# \$1M to advance AI tools\n\n2026-06-26\n\nDARPA is launching")
        ->toContain("| 1st place | \$300,000 |\n|---|---|\n| 2nd place | \$150,000 |")
        ->toContain('<outreach@darpa.mil>')
        ->not->toContain('##');

    // Without any <h1> on the page, the update entry's title stands in.
    Http::fake(['www.example.org/news/3' => Http::response(str_replace('<header><h1> $1M to advance   AI tools </h1></header>', '', $page), 200, ['Content-Type' => 'text/html'])]);
    expect(fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/3', 'title' => 'Entry title']))->markdown)->toStartWith("# Entry title\n\n2026-06-26\n\n");

    // With a page <h1> and an entry title that differ, what the update list said wins (the page's <h1> is often the site or the section).
    Http::fake(['www.example.org/news/2' => Http::response($page, 200, ['Content-Type' => 'text/html'])]);
    $untitled = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/2', 'title' => 'Entry title']));
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

    $document = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/1', 'title' => 'アンモニア燃焼器の開発を開始']));

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
    // One rule per line; the second needs both words, so 水素セミナー開催のお知らせ is excluded by 開催; セミナー and not by 採用情報.
    EditorialPolicy::query()->create(['layer' => 'exclude_keywords', 'body' => "採用情報\n開催; セミナー ;\n\n掲載"]);
    $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
        .'<item><title>アンモニア燃焼器の開発を開始</title><link>https://www.example.org/news/1</link></item>'
        .'<item><title>水素セミナー開催のお知らせ</title><link>https://www.example.org/news/2</link></item></channel></rss>';
    Http::fake(['www.example.org/rss.xml' => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    app(FetchUpdates::class)($source);

    Queue::assertPushed(FetchDocument::class, 1);
    $excluded = Document::query()->where('url', 'https://www.example.org/news/2')->sole();
    expect($excluded->excluded_by)->toBe('開催; セミナー')->and($excluded->status)->toBeNull()
        ->and(Document::query()->where('url', 'https://www.example.org/news/1')->sole()->excluded_by)->toBeNull();
    // The excluded entry is listed as 対象外 on 文書 and on its source, next to the failed ones; its own screen says why it was excluded.
    $this->get(route('documents.index'))->assertSee($excluded->title)->assertSee('対象外');
    $this->get(route('sources.show', $source))->assertSee($excluded->title)->assertSee('除外キーワード「開催; セミナー」に一致');
    $this->get(route('documents.show', $excluded))->assertSee('除外キーワード「開催; セミナー」に一致');

    // The source's "fetch documents" leaves excluded entries alone; the entry's own button still fetches it.
    Livewire::test('pages::sources.show', ['source' => $source])->call('fetchDocuments');
    Queue::assertPushed(FetchDocument::class, 1);
    Livewire::test('pages::documents.show', ['document' => $excluded])->call('fetchDocument');
    Queue::assertPushed(FetchDocument::class, 2);
});

// The title filter is one setting over every source, applied before fetching, so it lives on 情報源; the content filtering (LLM criteria) judges what was fetched, so it lives on 文書.
it('saves the title filter from the sources screen and the content filtering from the documents screen', function () {
    // Saving applies the rules to the documents already listed: a fetched one is marked, an earlier mark from a dropped rule is removed.
    $fetched = Document::factory()->fetched()->create(['title' => '研究員の寄稿が日経に掲載されました']);
    $kept = Document::factory()->fetched()->create(['title' => '新技術が学会誌に掲載']);
    $formerly = Document::factory()->create(['title' => '水素セミナー開催のお知らせ', 'excluded_by' => 'セミナー']);

    Livewire::test('pages::sources.index')
        ->assertSet('excludeKeywords', '')
        ->set('excludeKeywords', "採用情報\n寄稿; 掲載")
        ->call('saveTitleFilter')->assertHasNoErrors();
    expect($fetched->refresh()->excluded_by)->toBe('寄稿; 掲載')->and($fetched->status)->toBe('fetched')
        ->and($kept->refresh()->excluded_by)->toBeNull()
        ->and($formerly->refresh()->excluded_by)->toBeNull();
    Livewire::test('pages::documents.index')
        ->assertSet('contentFilteringModel', 'gpt-5.6-terra')
        ->set('contentFiltering', '技術的な発表を採用し、人事は採用しない')->set('contentFilteringModel', 'gpt-5.6-sol')
        ->call('saveContentFiltering')->assertHasNoErrors();
    // The strongest model is kept for reviewing and cannot be the screening's model.
    Livewire::test('pages::documents.index')->set('contentFilteringModel', 'gpt-2')->call('saveContentFiltering')->assertHasErrors(['contentFilteringModel']);
    Livewire::test('pages::documents.index')->set('contentFilteringModel', 'gpt-6-astra')->call('saveContentFiltering')->assertHasErrors(['contentFilteringModel']);

    expect(EditorialPolicy::excludeKeywords())->toBe([['採用情報'], ['寄稿', '掲載']])
        ->and(EditorialPolicy::excludedBy('研究員の寄稿が日経に掲載されました'))->toBe('寄稿; 掲載')
        ->and(EditorialPolicy::excludedBy('新技術が学会誌に掲載'))->toBeNull()
        ->and(EditorialPolicy::bodyFor('content_filtering'))->toBe('技術的な発表を採用し、人事は採用しない')
        ->and(EditorialPolicy::modelFor('content_filtering'))->toBe('gpt-5.6-sol');
    $this->get(route('editorial-policy'))->assertSee('タイトルフィルタは「情報源」、コンテンツフィルタリングは「文書」の画面で設定します。');
});

it('asks the agent for document settings when the source has none, verifies them on the page, and saves them for the next documents', function () {
    Http::fake([
        'www.example.org/news/*' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'article', 'date' => 'time', 'remove' => '.share', 'fixed_text' => ''])),
    ]);
    $source = Source::factory()->create();
    $first = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

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
    $second = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/2']));
    expect($second->status)->toBe('fetched')->and($second->status_message)->toBeNull();
    Http::assertSentCount(7); // robots.txt (page host), page 1, robots.txt (source host), favicon.ico, agent, page 2, favicon.ico
});

// A fetched body under Document::SHORT_BODY_CHARS is flagged on the lists and the document (the settings may catch a teaser); from the source, the agent can propose settings again from a short document's original, kept only when they yield more.
it('warns of short bodies and lets the agent propose the document settings again from one', function () {
    // The page: a teaser in the header the current settings catch, the body in a section they miss.
    $page = '<html><body><header><h1>Ammonia burner programme</h1><p class="teaser">'.str_repeat('A short teaser. ', 10).'</p></header>'
        .'<section class="body"><time datetime="2026-09-17">2026-09-17</time>'.str_repeat('<p>'.str_repeat('The long body of the release. ', 8).'</p>', 6).'</section></body></html>';
    Http::fake([
        'www.example.org/news/*' => Http::response($page, 200, ['Content-Type' => 'text/html']),
        // The agent's proposals, in turn: one that reaches the body, then one that does not (Http::fake keeps its first callback, hence the sequence).
        'api.openai.com/*' => Http::sequence()
            ->push(documentAgentAnswer(['content' => 'section.body', 'date' => 'time', 'remove' => '', 'fixed_text' => '']))
            ->push(documentAgentAnswer(['content' => 'header', 'date' => '', 'remove' => '', 'fixed_text' => ''])),
    ]);
    $source = Source::factory()->create(['document_config' => ['content' => 'header', 'date' => '', 'remove' => '', 'fixed_text' => '']]);
    $document = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/1', 'title' => 'Ammonia burner programme']));

    expect($document->hasShortBody())->toBeTrue();
    $this->get(route('documents.index'))->assertSee('本文が短い');
    $this->get(route('documents.show', $document))->assertSee('字しかありません')->assertSee($source->name.' の文書の設定');
    $this->get(route('sources.index'))->assertSee('本文が短い');

    Livewire::test('pages::sources.show', ['source' => $source])->assertSee('本文が短い（1000 字未満）')->call('proposeDocumentSettings')->assertHasNoErrors();

    expect($source->refresh()->document_config)->toEqual(['content' => 'section.body', 'date' => 'time', 'remove' => '', 'fixed_text' => ''])
        ->and($document->refresh()->hasShortBody())->toBeFalse()
        ->and($document->markdown)->toContain('The long body of the release.')->not->toContain('A short teaser');
    $this->get(route('documents.show', $document))->assertDontSee('字しかありません');

    // A proposal that yields no more than now is not kept.
    $source->update(['document_config' => ['content' => 'header', 'date' => '', 'remove' => '', 'fixed_text' => '']]);
    $document->update(['markdown' => '# Ammonia burner programme'.str_repeat("\n\nA short teaser.", 10)]);
    Livewire::test('pages::sources.show', ['source' => $source])->call('proposeDocumentSettings')->assertHasNoErrors();
    expect($source->refresh()->document_config['content'])->toBe('header');
});

it('falls back to generic selectors when the proposed content selector finds nothing, and saves what worked', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'div.press-body', 'remove' => ''])),
    ]);
    $source = Source::factory()->create();

    $document = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('fetched')
        ->and($source->refresh()->document_config)->toMatchArray(['content' => 'article', 'remove' => '']);
});

// 日立: every article page numbers its container (#content-17863846), so the agent proposes a selector that fits one page only.
it('generalises a proposed selector that names the page number, and takes the configured date before the removals', function () {
    $page = fn (int $number): string => "<html><body><div id=\"main\"><div id=\"content-{$number}\" class=\"content\"><h1>Article {$number}</h1>"
        .'<div class="content-info"><a href="/_users/1">H</a><div class="content-pubdate">2026-09-17</div></div>'
        .'<p>Hitachi developed a method that extracts practical knowledge from operation logs on a 3D digital twin to support maintenance sites.</p>'
        .'</div></div></body></html>';
    Http::fake([
        'www.example.org/_ct/17863846' => Http::response($page(17863846), 200, ['Content-Type' => 'text/html']),
        'www.example.org/_ct/17864379' => Http::response($page(17864379), 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => '#content-17863846', 'date' => '#content-17863846 .content-pubdate', 'remove' => '.content-info', 'fixed_text' => ''])),
    ]);
    $source = Source::factory()->create();

    $first = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/_ct/17863846', 'title' => 'Article 17863846']));
    $second = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/_ct/17864379', 'title' => 'Article 17864379']));

    expect($source->refresh()->document_config)->toEqual(['content' => '[id^="content-"]', 'date' => '[id^="content-"] .content-pubdate', 'remove' => '.content-info', 'fixed_text' => ''])
        ->and($first->markdown)->toStartWith("# Article 17863846\n\n2026-09-17\n\nHitachi developed")->not->toContain('[H]')
        ->and($second->status_message)->toBeNull()
        ->and($second->markdown)->toStartWith("# Article 17864379\n\n2026-09-17\n\n");
    Http::assertSentCount(7); // robots.txt (page host), page 1, robots.txt (source host), favicon.ico, agent, page 2, favicon.ico
});

// The site changed its layout: the saved settings match nothing, so the agent is asked again.
it('asks the agent again when the saved document settings no longer match the page', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'article', 'remove' => ''])),
    ]);
    $source = Source::factory()->create(['document_config' => ['content' => 'div.old-layout', 'remove' => '']]);

    $document = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('fetched')
        ->and($source->refresh()->document_config['content'])->toBe('article');
});

it('fails with a clear message when neither the proposal nor the generic selectors find a body', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response('<html><body><div class="x"><p>Short.</p></div></body></html>', 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'div.x', 'remove' => ''])),
    ]);
    $source = Source::factory()->create();

    $document = fetchDocument(Document::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('failed')
        ->and($document->status_message)->toContain('本文を見つけられませんでした')
        ->and($source->refresh()->document_config)->toBeNull();
});

it('reads a PDF as text and keeps the original', function () {
    $pdf = (string) file_get_contents(base_path('tests/Fixtures/press-release.pdf'));
    Http::fake(['www.example.org/press/1.pdf' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])]);
    $source = Source::factory()->create();
    $entry = Document::factory()->for($source)->create(['url' => 'https://www.example.org/press/1.pdf', 'title' => 'A PDF press release']);

    $document = fetchDocument($entry);

    // The title comes from the update list (the page prints none bigger than the body); the two lines of one paragraph run together.
    expect($document)->toMatchArray(['status' => 'fetched', 'format' => 'pdf', 'original_path' => "documents/{$source->id}/{$entry->id}.pdf"])
        ->and($document->markdown)->toBe("# A PDF press release\n\nHello from a PDF press release. Second line of the release.");
    Storage::disk('local')->assertExists("documents/{$source->id}/{$entry->id}.pdf");
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

// A secured PDF with an empty user password (RC4, AES-128 or AES-256) is decrypted and read like any other; one that needs a password is reported as such.
it('reads secured PDFs', function () {
    $read = app(ReadDocument::class);

    foreach (['rc4', 'aes128', 'aes256'] as $cipher) {
        expect($read->pdf((string) file_get_contents(base_path("tests/Fixtures/press-release-{$cipher}.pdf"))))
            ->toBe('Hello from a PDF press release. Second line of the release.', $cipher);
    }

    expect(fn () => $read->pdf((string) file_get_contents(base_path('tests/Fixtures/press-release-password.pdf'))))
        ->toThrow(RuntimeException::class, 'この PDF は開くのにパスワードが必要です。');
});

// A PDF has no structure of its own: the Markdown is read from the layout (App\Pdf\PdfMarkdown), in the shape of an HTML page.
it('reads title, date, headings, a table, a list and a footnote mark from the layout of a PDF', function () {
    $pdf = (string) file_get_contents(base_path('tests/Fixtures/press-release-layout.pdf'));
    $expected = "# Compact ammonia burners for industrial furnaces\n\n2026-09-17\n\n"
        // Two lines of one paragraph, the footnote mark raised beside the text staying in its line; the page number at the foot is gone.
        ."The agency announced a programme to develop compact ammonia burners for industrial furnaces, cutting CO2 from heat.1\n\n"
        // Lines printed bigger than the body are headings.
        ."## Background\n\nAmmonia carries hydrogen without the cost of liquefaction and can be burned directly.\n\n## Programme outline\n\n"
        // Cells in columns make a table; the label centred beside two lines of value gets both.
        ."| Budget | 2 billion yen |\n|---|---|\n| Period | From fiscal 2026 to fiscal 2029, with a review at the halfway point. |\n| Partners | Three universities |\n\n"
        // Bullets make list items; the smaller footnote is a paragraph of its own.
        ."Goals:\n\n- Halve the burner volume\n- Keep NOx under the current limit\n\n1 Measured against a gas burner of the same output.";

    // The title printed on the page is what the update list said, spaces aside; without a listed title, the biggest lines at the top are it.
    expect(app(ReadDocument::class)->pdf($pdf, 'Compact ammonia burners for industrial furnaces'))->toBe($expected)
        ->and(app(ReadDocument::class)->pdf($pdf))->toBe($expected);
});

it('records a failure instead of throwing when the page cannot be fetched', function () {
    Http::fake(['www.example.org/*' => Http::response('gone', 500)]);

    $document = fetchDocument(Document::factory()->create(['url' => 'https://www.example.org/news/1']));

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
    $missing = Document::factory()->for($source)->create();
    $failed = Document::factory()->for($source)->create(['status' => 'failed']);
    $fetched = Document::factory()->for($source)->fetched()->create();

    Livewire::test('pages::sources.show', ['source' => $source])->call('fetchDocuments');

    Queue::assertPushed(FetchDocument::class, 2);
    expect($missing->refresh()->status)->toBe('fetching')
        ->and($failed->refresh()->status)->toBe('fetching')
        ->and($fetched->refresh()->status)->toBe('fetched');

    Livewire::test('pages::documents.show', ['document' => $fetched])->call('fetchDocument');

    Queue::assertPushed(FetchDocument::class, 3);
    expect($fetched->refresh()->status)->toBe('fetching');
});

// After the document settings or the Markdown rules changed, every document of a source can be taken again at once.
it('queues every document of a source again from its screen, except the excluded and the ones being fetched', function () {
    Queue::fake();
    $source = Source::factory()->create();
    $fetched = Document::factory()->for($source)->fetched()->create();
    $missing = Document::factory()->for($source)->create();
    Document::factory()->for($source)->create(['status' => 'fetching']);
    Document::factory()->for($source)->create(['excluded_by' => 'セミナー']);
    Document::factory()->create();

    Livewire::test('pages::sources.show', ['source' => $source])->call('fetchAllDocumentsAgain');

    Queue::assertPushed(FetchDocument::class, 2);
    expect($fetched->refresh()->status)->toBe('fetching')
        ->and($missing->refresh()->status)->toBe('fetching');
});

// The Markdown of a source's documents can be made again from the originals on disk, without asking the site.
it('rebuilds the Markdown of every document of a source from the originals, with the current settings', function () {
    $source = Source::factory()->create(['document_config' => ['content' => 'article', 'remove' => '.share']]);
    $entry = Document::factory()->for($source)->create(['title' => 'Ammonia burner programme', 'format' => 'html', 'original_path' => "documents/{$source->id}/1.html", 'markdown' => 'old', 'status' => 'failed']);
    Storage::disk('local')->put("documents/{$source->id}/1.html", DOCUMENT_PAGE);
    $unreadable = Document::factory()->for($source)->create(['format' => 'html', 'original_path' => "documents/{$source->id}/2.html", 'markdown' => 'old', 'status' => 'fetched']);
    Storage::disk('local')->put("documents/{$source->id}/2.html", '<html><body><div class="x"><p>No article element here.</p></div></body></html>');
    $elsewhere = Document::factory()->fetched()->create(['markdown' => 'old']);

    Livewire::test('pages::sources.show', ['source' => $source])->call('rebuildMarkdown');

    expect($entry->refresh()->markdown)->toStartWith("# Ammonia burner programme\n\n2026-09-17\n\n")
        ->and($entry->status)->toBe('fetched')
        ->and($entry->status_message)->toContain('原本から')
        ->and($unreadable->refresh()->markdown)->toBe('old')
        ->and($elsewhere->refresh()->markdown)->toBe('old');
    Http::assertNothingSent();
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
    $entry = Document::factory()->fetched()->create(['original_path' => 'documents/1/1.html']);

    $this->get(route('documents.original', $entry))->assertOk()->assertDownload('1.html');
});
