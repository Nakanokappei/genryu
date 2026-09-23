<?php

use App\Actions\FetchUpdates;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const RSS = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Agency news</title>
<item><title>First release</title><link>https://www.example.org/news/first</link><pubDate>Mon, 21 Sep 2026 09:00:00 GMT</pubDate></item>
<item><title>Second release</title><link>https://www.example.org/news/second</link><pubDate>Sun, 20 Sep 2026 09:00:00 GMT</pubDate></item>
</channel></rss>
XML;

const ATOM = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"><title>Agency</title>
<entry><title>Atom release</title><link rel="alternate" href="https://www.example.org/news/atom"/><published>2026-09-19T00:00:00Z</published></entry>
</feed>
XML;

beforeEach(function () {
    // Tests never hit the network: an unfaked URL fails the test instead of leaving the machine.
    Http::preventStrayRequests();
    // No robots.txt anywhere unless a test says otherwise (registered first, so it wins for that path).
    // Sites without an icon: the favicon fetch that comes with reading a source finds nothing.
    Http::fake(['*/robots.txt' => Http::response('', 404), '*/favicon.ico' => Http::response('', 404)]);
    // Each new entry queues its document fetch (stage 2.2); the queue is faked so the fetch does not run here.
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

// Order of discovery: the URL is a feed; or the HTML page advertises one; or there is nothing to read yet.
it('reads an RSS feed given directly as the source URL', function () {
    Http::fake(['www.example.org/rss.xml' => Http::response(RSS, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    $result = app(FetchUpdates::class)($source);

    expect($result)->toMatchArray(['feed_url' => 'https://www.example.org/rss.xml', 'added' => 2, 'existing' => 0])
        ->and(Document::query()->where('url', 'https://www.example.org/news/first')->sole())->toMatchArray(['title' => 'First release'])
        // A pubDate names the time and its zone, so it is kept and shown as 公開日時 in the display timezone (09:00 GMT is 18:00 in Tokyo).
        ->and(Document::query()->where('url', 'https://www.example.org/news/first')->sole())->toMatchArray(['published_has_time' => true])
        ->and(Document::query()->where('url', 'https://www.example.org/news/first')->sole()->publishedDisplay())->toBe('2026-09-21 18:00')
        ->and(Document::query()->where('url', 'https://www.example.org/news/first')->sole()->published_at?->toDateString())->toBe('2026-09-21')
        ->and($source->refresh()->feed_url)->toBe('https://www.example.org/rss.xml')
        ->and($source->fetched_at)->not->toBeNull();
});

it('finds the feed an HTML page advertises and reads it', function () {
    Http::fake([
        'www.example.org/news' => Http::response('<html><head><link rel="alternate" type="application/atom+xml" href="/feed.atom"></head><body>list</body></html>', 200, ['Content-Type' => 'text/html']),
        'www.example.org/feed.atom' => Http::response(ATOM, 200, ['Content-Type' => 'application/atom+xml']),
    ]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/news']);

    $result = app(FetchUpdates::class)($source);

    expect($result)->toMatchArray(['feed_url' => 'https://www.example.org/feed.atom', 'added' => 1])
        ->and(Document::query()->sole()->title)->toBe('Atom release');
});

// DARPA: no <link rel="alternate"> on /news, but /rss.xml exists.
it('probes well-known feed paths when a page advertises none', function () {
    Http::fake([
        'www.example.org/news' => Http::response('<html><body>list</body></html>', 200, ['Content-Type' => 'text/html']),
        'www.example.org/rss.xml' => Http::response(RSS, 200, ['Content-Type' => 'application/rss+xml']),
        'www.example.org/*' => Http::response('not found', 404),
    ]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/news']);

    $result = app(FetchUpdates::class)($source);

    expect($result)->toMatchArray(['feed_url' => 'https://www.example.org/rss.xml', 'added' => 2]);
});

it('stops with a clear message when a page has no feed and no HTML list settings', function () {
    Http::fake(['www.example.org/*' => Http::response('<html><body><ul><li><a href="/a">A</a></li></ul></body></html>', 200, ['Content-Type' => 'text/html'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/list']);

    expect(fn () => app(FetchUpdates::class)($source))->toThrow(RuntimeException::class, 'RSS / Atom フィードが見つかりません');
    expect(Document::query()->count())->toBe(0)->and($source->refresh()->fetched_at)->toBeNull();
});

/**
 * A NEDO-shaped list page: a header row, rows with a <time> and a title link, a next link.
 */
function listPage(int $page, int $lastPage, array $items): string
{
    $rows = implode('', array_map(fn (array $item): string => "<tr><td><time datetime=\"{$item[2]}\">{$item[3]}</time></td><td><a href=\"{$item[0]}\">{$item[1]}</a></td></tr>", $items));
    $next = $page < $lastPage ? '<a href="/list?p='.($page + 1).'#table" title="next page">次へ</a>' : '';

    return "<html><body><table class=\"table1\"><tr><th>掲載日</th><th>件名</th></tr>{$rows}</table>{$next}</body></html>";
}

const LIST_CONFIG = ['item' => 'table.table1 tr', 'title' => 'td a', 'date' => 'time', 'next' => 'a[title="next page"]', 'max_pages' => 2];

it('reads an HTML list with the source settings, page by page, within the page budget', function () {
    Http::fake([
        'www.example.org/list?p=2' => Http::response(listPage(2, 3, [['/news/3.html', 'Third', '2026-09-01', '2026年9月1日']]), 200, ['Content-Type' => 'text/html']),
        'www.example.org/list?p=3' => Http::response(listPage(3, 3, [['/news/4.html', 'Fourth', '2026-08-01', '2026年8月1日']]), 200, ['Content-Type' => 'text/html']),
        'www.example.org/list' => Http::response(listPage(1, 3, [['/news/1.html', 'First', '2026-09-17', '2026年9月17日'], ['/news/2.html', 'Second', '', '2026年9月8日']]), 200, ['Content-Type' => 'text/html']),
    ]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/list', 'list_config' => LIST_CONFIG]);

    $result = app(FetchUpdates::class)($source);

    // Two pages read (max_pages 2), the header row skipped, dates from datetime= or from Japanese text.
    expect($result)->toMatchArray(['feed_url' => null, 'pages' => 2, 'added' => 3, 'existing' => 0])
        ->and(Document::query()->orderBy('id')->pluck('url')->all())->toBe(['https://www.example.org/news/1.html', 'https://www.example.org/news/2.html', 'https://www.example.org/news/3.html'])
        ->and(Document::query()->where('title', 'First')->sole()->published_at?->toDateString())->toBe('2026-09-17')
        // A list gives the day alone: no time to show, and the day is not moved by the display timezone.
        ->and(Document::query()->where('title', 'First')->sole())->toMatchArray(['published_has_time' => false])
        ->and(Document::query()->where('title', 'First')->sole()->publishedDisplay())->toBe('2026-09-17')
        ->and(Document::query()->where('title', 'Second')->sole()->published_at?->toDateString())->toBe('2026-09-08');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'p=3'));
});

// CNRS's pager links are query-only (?field…=354&page=1) and must resolve against the page's own path.
it('resolves query-only and relative next links against the page', function () {
    Http::fake([
        'www.example.org/fr/newsroom?tag=354&page=1' => Http::response(listPage(2, 2, [['/fr/presse/b', 'B', '2026-09-01', '']]), 200, ['Content-Type' => 'text/html']),
        'www.example.org/fr/newsroom?tag=354' => Http::response(str_replace('href="/list?p=2#table"', 'href="?tag=354&amp;page=1"', listPage(1, 2, [['presse/a', 'A', '2026-09-17', '']])), 200, ['Content-Type' => 'text/html']),
    ]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/fr/newsroom?tag=354', 'list_config' => LIST_CONFIG]);

    $result = app(FetchUpdates::class)($source);

    expect($result)->toMatchArray(['pages' => 2, 'added' => 2])
        ->and(Document::query()->orderBy('id')->pluck('url')->all())->toBe(['https://www.example.org/fr/presse/a', 'https://www.example.org/fr/presse/b']);
});

it('stops paging at the first page with nothing new', function () {
    Http::fake([
        'www.example.org/list?p=2' => Http::response(listPage(2, 2, [['/news/2.html', 'Second', '2026-09-01', '']]), 200, ['Content-Type' => 'text/html']),
        'www.example.org/list' => Http::response(listPage(1, 2, [['/news/1.html', 'First', '2026-09-17', '']]), 200, ['Content-Type' => 'text/html']),
    ]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/list', 'list_config' => [...LIST_CONFIG, 'max_pages' => 5]]);

    app(FetchUpdates::class)($source);
    Http::assertSentCount(4); // robots.txt, page 1, page 2, favicon.ico

    $second = app(FetchUpdates::class)($source);

    // Page 1 is entirely known, so page 2 is not requested again (robots.txt is cached); the icon is looked for again.
    expect($second)->toMatchArray(['pages' => 1, 'added' => 0, 'existing' => 1]);
    Http::assertSentCount(6);
});

/**
 * A 三菱電機-shaped JSON list: an object with the items under "news", newest first, relative links, Japanese dates.
 */
const JSON_LIST = '{"news":[{"date":"2026年09月17日","title":"量子コンピューターの大規模化に向けた研究開発を開始","url":"/ja/pr/2026/0917_rd/","thumb":"x"},'
    .'{"date":"2026年09月17日","title":"安全シーケンサで EU 型式認証を取得","url":"/ja/pr/2026/0917_fa/"},'
    .'{"date":"2026年09月15日","title":"SWISSto12 と覚書を締結","url":"/ja/pr/2026/0915_ds/"},'
    .'{"date":"2026年09月10日","title":"Fourth","url":"/ja/pr/2026/0910/"}],"meta":{"count":"4"}}';

const JSON_CONFIG = ['url' => 'https://www.example.org/data/news-article.json', 'items' => 'news', 'title' => 'title', 'link' => 'url', 'date' => 'date', 'max_items' => 3];

it('reads a JSON list with the source settings, newest first, up to max_items', function () {
    Http::fake(['www.example.org/data/news-article.json' => Http::response(JSON_LIST, 200, ['Content-Type' => 'application/json'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/ja/pr/', 'json_config' => JSON_CONFIG]);

    $result = app(FetchUpdates::class)($source);

    expect($result)->toMatchArray(['feed_url' => null, 'pages' => 1, 'added' => 3, 'existing' => 0])
        ->and(Document::query()->orderBy('id')->pluck('url')->all())->toBe(['https://www.example.org/ja/pr/2026/0917_rd/', 'https://www.example.org/ja/pr/2026/0917_fa/', 'https://www.example.org/ja/pr/2026/0915_ds/'])
        ->and(Document::query()->where('url', 'like', '%0915_ds%')->sole()->published_at?->toDateString())->toBe('2026-09-15');
});

it('reports JSON list settings that match nothing', function () {
    Http::fake(['www.example.org/data/news-article.json' => Http::response('{"items": []}', 200, ['Content-Type' => 'application/json'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/ja/pr/', 'json_config' => JSON_CONFIG]);

    expect(fn () => app(FetchUpdates::class)($source))->toThrow(RuntimeException::class, 'JSON 一覧の設定に一致する項目がありません');
});

it('saves the JSON list settings from the source detail screen', function () {
    $source = Source::factory()->create();

    Livewire::test('pages::editorial.sources.show', ['source' => $source])
        ->set('json.url', 'https://www.example.org/data/news-article.json')->set('json.items', 'news')->set('json.date', 'date')->set('json.max_items', '20')
        ->call('saveJson')->assertHasNoErrors();
    expect($source->refresh()->json_config)->toEqual(['url' => 'https://www.example.org/data/news-article.json', 'items' => 'news', 'title' => 'title', 'link' => 'url', 'date' => 'date', 'max_items' => 20]);

    // Clearing the URL stops reading from JSON.
    Livewire::test('pages::editorial.sources.show', ['source' => $source])
        ->assertSet('json.items', 'news')
        ->set('json.url', '')
        ->call('saveJson')->assertHasNoErrors();
    expect($source->refresh()->json_config)->toBeNull();
});

it('reports HTML list settings that match nothing', function () {
    Http::fake(['www.example.org/*' => Http::response('<html><body><p>no table</p></body></html>', 200, ['Content-Type' => 'text/html'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/list', 'list_config' => LIST_CONFIG]);

    expect(fn () => app(FetchUpdates::class)($source))->toThrow(RuntimeException::class, '一致する項目がページにありません');
});

it('saves the HTML list settings from the source detail screen', function () {
    $source = Source::factory()->create();

    Livewire::test('pages::editorial.sources.show', ['source' => $source])
        ->set('list.item', 'table.table1 tr')->set('list.title', 'td a')->set('list.date', 'time')->set('list.next', 'a[title="next page"]')->set('list.max_pages', '4')
        ->call('saveList')->assertHasNoErrors();
    expect($source->refresh()->list_config)->toEqual(['item' => 'table.table1 tr', 'title' => 'td a', 'date' => 'time', 'next' => 'a[title="next page"]', 'max_pages' => 4]);

    // Clearing the item selector goes back to reading a feed.
    Livewire::test('pages::editorial.sources.show', ['source' => $source])
        ->assertSet('list.item', 'table.table1 tr')
        ->set('list.item', '')
        ->call('saveList')->assertHasNoErrors();
    expect($source->refresh()->list_config)->toBeNull();
});

it('does not list the same URL twice for a source', function () {
    Http::fake(['www.example.org/rss.xml' => Http::response(RSS, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    app(FetchUpdates::class)($source);
    $second = app(FetchUpdates::class)($source);

    expect($second)->toMatchArray(['added' => 0, 'existing' => 2])
        ->and(Document::query()->count())->toBe(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', FetchUpdates::USER_AGENT));
});

it('runs from the source detail screen', function () {
    Http::fake(['www.example.org/rss.xml' => Http::response(RSS, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    // The listed documents are queued for fetching, not shown on the source (its list holds the failed ones only).
    Livewire::test('pages::editorial.sources.show', ['source' => $source])
        ->call('fetchUpdates')
        ->assertHasNoErrors()
        ->assertDontSee('First release');

    expect($source->refresh()->documents()->count())->toBe(2);
});
