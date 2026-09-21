<?php

use App\Actions\FetchUpdates;
use App\Models\Source;
use App\Models\UpdateEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
    $this->actingAs(User::factory()->create());
});

// Order of discovery: the URL is a feed; or the HTML page advertises one; or there is nothing to read yet.
it('reads an RSS feed given directly as the source URL', function () {
    Http::fake(['www.example.org/rss.xml' => Http::response(RSS, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    $result = app(FetchUpdates::class)($source);

    expect($result)->toMatchArray(['feed_url' => 'https://www.example.org/rss.xml', 'added' => 2, 'existing' => 0])
        ->and(UpdateEntry::query()->where('url', 'https://www.example.org/news/first')->sole())->toMatchArray(['title' => 'First release'])
        ->and(UpdateEntry::query()->where('url', 'https://www.example.org/news/first')->sole()->published_at?->toDateString())->toBe('2026-09-21')
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
        ->and(UpdateEntry::query()->sole()->title)->toBe('Atom release');
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

it('stops with a clear message when a page has no feed', function () {
    Http::fake(['www.example.org/*' => Http::response('<html><body><ul><li><a href="/a">A</a></li></ul></body></html>', 200, ['Content-Type' => 'text/html'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/list']);

    expect(fn () => app(FetchUpdates::class)($source))->toThrow(RuntimeException::class, 'RSS / Atom フィードが見つかりません');
    expect(UpdateEntry::query()->count())->toBe(0)->and($source->refresh()->fetched_at)->toBeNull();
});

it('does not list the same URL twice for a source', function () {
    Http::fake(['www.example.org/rss.xml' => Http::response(RSS, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    app(FetchUpdates::class)($source);
    $second = app(FetchUpdates::class)($source);

    expect($second)->toMatchArray(['added' => 0, 'existing' => 2])
        ->and(UpdateEntry::query()->count())->toBe(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', FetchUpdates::USER_AGENT));
});

it('runs from the source detail screen', function () {
    Http::fake(['www.example.org/rss.xml' => Http::response(RSS, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    Livewire::test('pages::sources.show', ['source' => $source])
        ->call('fetchUpdates')
        ->assertHasNoErrors()
        ->assertSee('First release');

    expect($source->refresh()->updateEntries()->count())->toBe(2);
});
