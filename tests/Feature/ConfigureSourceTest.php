<?php

use App\Actions\FetchFavicon;
use App\Actions\FetchUpdates;
use App\Actions\ProposeListSettings;
use App\Jobs\ConfigureSource;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const CONFIGURE_RSS = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title><item><title>One</title><link>https://www.example.org/news/1</link></item></channel></rss>';

const CONFIGURE_LIST = '<html><body><table class="table1"><tr><th>掲載日</th><th>件名</th></tr>'
    .'<tr><td><time datetime="2026-09-17">2026年9月17日</time></td><td><a href="/news/a.html">A</a></td></tr>'
    .'<tr><td><time datetime="2026-09-08">2026年9月8日</time></td><td><a href="/news/b.html">B</a></td></tr>'
    .'<tr><td><time datetime="2026-09-01">2026年9月1日</time></td><td><a href="/news/c.html">C</a></td></tr>'
    .'</table></body></html>';

/**
 * What the agent would answer, as the OpenAI chat completion wire format.
 */
function agentAnswer(array $selectors): array
{
    return ['choices' => [['message' => ['content' => json_encode($selectors)]]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*/robots.txt' => Http::response('', 404)]);
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4o-mini']);
    // The first update-list read queues a document fetch per entry (stage 2.2); faked so nothing runs here.
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

function configure(Source $source): Source
{
    (new ConfigureSource($source))->handle(app(FetchUpdates::class), app(ProposeListSettings::class), app(FetchFavicon::class));

    return $source->refresh();
}

it('keeps the favicon the page advertises, or /favicon.ico, and serves it next to the name', function () {
    Storage::fake('local');
    Http::fake([
        'www.example.org/news' => Http::response('<html><head><link rel="alternate" type="application/rss+xml" href="/rss.xml"><link rel="shortcut icon" href="/img/icon.png"></head></html>', 200, ['Content-Type' => 'text/html']),
        'www.example.org/rss.xml' => Http::response(CONFIGURE_RSS, 200, ['Content-Type' => 'application/rss+xml']),
        'www.example.org/img/icon.png' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
        'www.example.org/favicon.ico' => Http::response('ICOBYTES', 200, ['Content-Type' => 'image/x-icon']),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/news']));

    expect($source->favicon_path)->toBe("favicons/{$source->id}.png");
    Storage::disk('local')->assertExists("favicons/{$source->id}.png");
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'favicon.ico'));
    $this->get(route('editorial.sources.favicon', $source))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('editorial.sources.index'))->assertSee(route('editorial.sources.favicon', $source));

    // A page that advertises no icon falls back to /favicon.ico; a site without any leaves the source blank.
    $plain = configure(Source::factory()->create(['url' => 'https://www.example.org/rss.xml']));
    expect($plain->favicon_path)->toBe("favicons/{$plain->id}.ico");

    Http::fake(['www.example.net/*' => Http::response('not found', 404)]);
    expect(configure(Source::factory()->create(['url' => 'https://www.example.net/news']))->favicon_path)->toBeNull();
});

// Every update list checks the icon again with If-Modified-Since: 304 keeps it, 200 with an icon replaces it, 404 forgets the URL so it is looked for afresh next time.
it('checks the favicon for a change on every update list', function () {
    Storage::fake('local');
    Http::fake([
        'www.example.org/news' => Http::response('<html><head><link rel="alternate" type="application/rss+xml" href="/rss.xml"><link rel="icon" href="/img/icon.png"></head></html>', 200, ['Content-Type' => 'text/html']),
        'www.example.org/rss.xml' => Http::response(CONFIGURE_RSS, 200, ['Content-Type' => 'application/rss+xml']),
        'www.example.org/img/icon.png' => Http::sequence()
            ->push('PNGBYTES', 200, ['Content-Type' => 'image/png', 'Last-Modified' => 'Tue, 01 Sep 2026 10:00:00 GMT'])
            ->push('', 304)
            ->push('NEWPNG', 200, ['Content-Type' => 'image/png', 'Last-Modified' => 'Tue, 22 Sep 2026 09:00:00 GMT'])
            ->push('gone', 404),
    ]);
    // Configuring takes the icon, then reads the list, which checks it again: 304, nothing changes.
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/news']));
    expect($source->favicon_url)->toBe('https://www.example.org/img/icon.png')->and($source->favicon_modified_at?->toIso8601String())->toBe('2026-09-01T10:00:00+00:00')
        ->and(Storage::disk('local')->get($source->favicon_path))->toBe('PNGBYTES');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.example.org/img/icon.png' && $request->header('If-Modified-Since') === ['Tue, 01 Sep 2026 10:00:00 GMT']);

    // 200 with a new icon: replaced, with its Last-Modified.
    app(FetchUpdates::class)($source);
    expect(Storage::disk('local')->get($source->refresh()->favicon_path))->toBe('NEWPNG')->and($source->favicon_modified_at?->toIso8601String())->toBe('2026-09-22T09:00:00+00:00');

    // 404: the URL is forgotten; the icon stays until a new one is found.
    app(FetchUpdates::class)($source);
    expect($source->refresh()->favicon_url)->toBeNull()->and($source->favicon_path)->not->toBeNull();
});

it('finds a feed deterministically, without asking the agent, and reads it', function () {
    Http::fake([
        'www.example.org/news' => Http::response('<html><head><link rel="alternate" type="application/rss+xml" href="/rss.xml"></head></html>', 200, ['Content-Type' => 'text/html']),
        'www.example.org/rss.xml' => Http::response(CONFIGURE_RSS, 200, ['Content-Type' => 'application/rss+xml']),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/news']));

    expect($source)->toMatchArray(['status' => 'ready', 'feed_url' => 'https://www.example.org/rss.xml', 'list_config' => null])
        ->and($source->status_message)->toContain('フィードを見つけました')
        ->and(Document::query()->count())->toBe(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

// 三菱電機: the page holds no entries; a script draws them from the JSON file named in a data attribute.
it('finds the JSON list a page draws its entries from, without asking the agent, and reads it', function () {
    $page = '<html><body><div data-js-component="newslist" data-js-newsjsonpath="/content/dam/news-article.json" data-js-prod-newsjsonpath="/global/common/news-data/news-article.json"></div>'
        .'<script src="/etc.clientlibs/site.min.js"></script></body></html>';
    $json = '{"news":[{"date":"2026年09月17日","title":"One","url":"/ja/pr/2026/0917_rd/"},{"date":"2026年09月17日","title":"Two","url":"/ja/pr/2026/0917_fa/"},{"date":"2026年09月15日","title":"Three","url":"/ja/pr/2026/0915_ds/"}],"meta":{"count":"3"}}';
    Http::fake([
        'www.example.org/ja/pr/' => Http::response($page, 200, ['Content-Type' => 'text/html']),
        'www.example.org/content/dam/news-article.json' => Http::response('not found', 404),
        'www.example.org/global/common/news-data/news-article.json' => Http::response($json, 200, ['Content-Type' => 'application/json']),
        'www.example.org/*' => Http::response('not found', 404),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/ja/pr/']));

    expect($source->status)->toBe('ready')
        ->and($source->json_config)->toEqual(['url' => 'https://www.example.org/global/common/news-data/news-article.json', 'items' => 'news', 'title' => 'title', 'link' => 'url', 'date' => 'date', 'max_items' => 50])
        ->and($source->list_config)->toBeNull()
        ->and($source->status_message)->toContain('JSON 一覧を見つけました')->toContain('3 件')
        ->and(Document::query()->orderBy('id')->pluck('title')->all())->toBe(['One', 'Two', 'Three']);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

it('asks the agent for HTML list settings when there is no feed, verifies them, saves them and reads the list', function () {
    Http::fake([
        'www.example.org/list' => Http::response(CONFIGURE_LIST, 200, ['Content-Type' => 'text/html']),
        'www.example.org/*' => Http::response('not found', 404),
        'api.openai.com/*' => Http::response(agentAnswer(['item' => 'table.table1 tr', 'title' => 'td a', 'date' => 'time', 'next' => ''])),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/list']));

    expect($source->status)->toBe('ready')
        ->and($source->list_config)->toEqual(['item' => 'table.table1 tr', 'title' => 'td a', 'date' => 'time', 'next' => '', 'max_pages' => 3])
        ->and($source->status_message)->toContain('3 件')
        ->and(Document::query()->count())->toBe(3);
    // The agent receives the page, without scripts, and must answer JSON.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com')
        && $request['response_format']['type'] === 'json_object'
        && str_contains($request['messages'][1]['content'], 'table1'));
});

// CNRS: /rss.xml exists but is a newsletter, not the press list the operator pointed at.
it('reports how many feed entries the page links to, and skips feeds when told to read the page as HTML', function () {
    Http::fake([
        'www.example.org/list' => Http::response(CONFIGURE_LIST, 200, ['Content-Type' => 'text/html']),
        'www.example.org/rss.xml' => Http::response(CONFIGURE_RSS, 200, ['Content-Type' => 'application/rss+xml']),
        'www.example.org/*' => Http::response('not found', 404),
        'api.openai.com/*' => Http::response(agentAnswer(['item' => 'table.table1 tr', 'title' => 'td a', 'date' => 'time', 'next' => ''])),
    ]);

    $probed = configure(Source::factory()->create(['url' => 'https://www.example.org/list']));
    expect($probed->status)->toBe('ready')->and($probed->feed_url)->toBe('https://www.example.org/rss.xml')
        ->and($probed->status_message)->toContain('0 件');

    $asHtml = configure(Source::factory()->create(['url' => 'https://www.example.org/list', 'read_as_html' => true]));
    expect($asHtml->status)->toBe('ready')->and($asHtml->feed_url)->toBeNull()
        ->and($asHtml->list_config['item'])->toBe('table.table1 tr');
});

// The 一覧の取得方法 tab chosen on the source detail screen is the choice to skip feeds; the HTML and JSON settings each drop the other when saved.
it('remembers the list method chosen on the source detail screen', function () {
    $source = Source::factory()->create();

    Livewire::test('pages::editorial.sources.show', ['source' => $source])->assertSet('method', 'feed')->set('method', 'html');
    expect($source->refresh()->read_as_html)->toBeTrue();

    Livewire::test('pages::editorial.sources.show', ['source' => $source])->assertSet('method', 'html')
        ->set('list.item', 'li')->set('list.title', 'a')->call('saveList')->assertHasNoErrors()
        ->set('method', 'json')->set('json.url', 'https://www.example.org/news.json')->call('saveJson')->assertHasNoErrors();
    expect($source->refresh()->list_config)->toBeNull()->and($source->json_config['url'])->toBe('https://www.example.org/news.json')->and($source->read_as_html)->toBeFalse();

    Livewire::test('pages::editorial.sources.show', ['source' => $source])->assertSet('method', 'json');
});

// CNRS: <a href><h2 class="article__title">…</h2></a>; the agent proposed "h2.article__title a", which is inside out.
it('falls back to generic title and date selectors when the proposed ones find nothing, and saves what worked', function () {
    $row = fn (int $i): string => "<div class=\"views-row\"><a href=\"/img/{$i}\" class=\"article__link\"><img alt=\"\"></a><time class=\"datetime\" datetime=\"2026-09-0{$i}\">0{$i}.09.2026</time><a href=\"/fr/presse/item-{$i}\"><h2 class=\"article__title\">Item {$i}</h2></a></div>";
    $page = '<html><body>'.$row(1).$row(2).$row(3).$row(4).'</body></html>';
    Http::fake([
        'www.example.org/list' => Http::response($page, 200, ['Content-Type' => 'text/html']),
        'www.example.org/*' => Http::response('not found', 404),
        'api.openai.com/*' => Http::response(agentAnswer(['item' => 'div.views-row', 'title' => 'h2.article__title a', 'date' => 'time.datetime', 'next' => ''])),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/list', 'read_as_html' => true]));

    expect($source->status)->toBe('ready')
        ->and($source->list_config)->toMatchArray(['item' => 'div.views-row', 'title' => 'h1, h2, h3, h4', 'date' => 'time.datetime'])
        ->and(Document::query()->pluck('url')->all())->toBe(['https://www.example.org/fr/presse/item-1', 'https://www.example.org/fr/presse/item-2', 'https://www.example.org/fr/presse/item-3', 'https://www.example.org/fr/presse/item-4'])
        ->and(Document::query()->where('title', 'Item 2')->sole()->published_at?->toDateString())->toBe('2026-09-02');
});

it('does not save a proposal that matches too little on the page', function () {
    Http::fake([
        'www.example.org/list' => Http::response(CONFIGURE_LIST, 200, ['Content-Type' => 'text/html']),
        'www.example.org/*' => Http::response('not found', 404),
        'api.openai.com/*' => Http::response(agentAnswer(['item' => 'div.card', 'title' => 'a', 'date' => '', 'next' => ''])),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/list']));

    expect($source->status)->toBe('failed')
        ->and($source->status_message)->toContain('0 件')
        ->and($source->list_config)->toBeNull()
        ->and(Document::query()->count())->toBe(0);
});

it('records a failure instead of throwing when the page cannot be fetched', function () {
    Http::fake(['www.example.org/*' => Http::response('gone', 500)]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/list']));

    expect($source->status)->toBe('failed')->and($source->status_message)->toContain('500');
});

it('can be queued again from the source detail screen', function () {
    Queue::fake();
    $source = Source::factory()->create(['status' => 'failed', 'status_message' => 'boom']);

    Livewire::test('pages::editorial.sources.show', ['source' => $source])->call('configure');

    expect($source->refresh()->status)->toBe('pending');
    Queue::assertPushed(ConfigureSource::class);
});
