<?php

use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\DiscoveredResource;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Tools\Discovery\DiscoverWebTool;
use App\Acquisition\Tools\ToolContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * A small official site: robots.txt with a sitemap, a home page advertising
 * a feed, a news index with pagination, PDFs, a private area and an
 * external link.
 */
function fakeOfficialSite(): void
{
    $newsLinks = implode('', array_map(fn (int $i): string => "<li><a href=\"/news/item-{$i}\">Item {$i}</a></li>", range(1, 12)));

    Http::fake([
        'www.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /private/\nSitemap: https://www.example.org/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']),
        'www.example.org/sitemap.xml' => Http::response(acquisitionFixture('synthetic/sitemap-index')['body'], 200, ['Content-Type' => 'application/xml']),
        'www.example.org/sitemap-news.xml' => Http::response(acquisitionFixture('synthetic/sitemap-basic')['body'], 200, ['Content-Type' => 'application/xml']),
        'www.example.org/feed.xml' => Http::response(acquisitionFixture('synthetic/rss-basic')['body'], 200, ['Content-Type' => 'application/rss+xml']),
        'www.example.org/news/?page=2' => Http::response('<html><body><main><a href="/news/item-13">Item 13</a></main></body></html>', 200, ['Content-Type' => 'text/html']),
        'www.example.org/news/' => Http::response("<html><head><title>News</title></head><body><main><ul>{$newsLinks}</ul><a href=\"/news/?page=2\">Next</a></main></body></html>", 200, ['Content-Type' => 'text/html']),
        'www.example.org/news/*' => Http::response('<html><body><article><h1>Item</h1><p>text</p></article></body></html>', 200, ['Content-Type' => 'text/html']),
        'www.example.org/private/*' => Http::response('secret', 200, ['Content-Type' => 'text/html']),
        'www.example.org/' => Http::response(<<<'HTML'
            <html><head><title>Example Agency</title>
            <link rel="canonical" href="https://www.example.org/">
            <link rel="alternate" type="application/rss+xml" title="News" href="/feed.xml">
            </head><body><main>
            <a href="/news/">News</a> <a href="/files/annual-report.pdf">Annual report (PDF)</a>
            <a href="/private/board">Board</a> <a href="https://partner.example.net/x">Partner</a>
            </main></body></html>
            HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'www.example.org/*' => Http::response('not found', 404),
        'partner.example.net/*' => Http::response('never', 200),
    ]);
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('acquisition');
    $this->source = Source::factory()->create(['key' => 'example', 'base_url' => 'https://www.example.org/']);
    $this->run = AcquisitionRun::factory()->for($this->source)->create();
    $this->tool = app(DiscoverWebTool::class);
    $this->context = ToolContext::forRun($this->run->id);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function discover(array $overrides = []): array
{
    $payload = ['seed_url' => 'https://www.example.org/', 'allowed_hosts' => ['www.example.org'], 'max_depth' => 1, 'max_urls' => 20, 'max_seconds' => 30, 'requests_per_minute' => 600, ...$overrides];

    return test()->tool->run(test()->tool->parseRequest($payload), test()->context)->toArray();
}

// AT-01: feeds, sitemaps, index pages, pagination and PDFs are found with evidence.
it('finds the stable acquisition routes of a site within its budget', function () {
    fakeOfficialSite();

    $result = discover();

    $feeds = collect($result['candidates']['feeds']);
    $sitemaps = collect($result['candidates']['sitemaps']);

    expect($feeds->pluck('url')->all())->toBe(['https://www.example.org/feed.xml'])
        ->and($feeds->first())->toMatchArray(['kind' => 'rss', 'entry_count' => 2, 'title' => 'Example Agency News'])
        ->and($sitemaps->firstWhere('url', 'https://www.example.org/sitemap.xml'))->toMatchArray(['kind' => 'sitemap_index', 'child_count' => 2])
        ->and($sitemaps->firstWhere('url', 'https://www.example.org/sitemap-news.xml'))->toMatchArray(['kind' => 'sitemap', 'entry_count' => 3])
        ->and(collect($result['candidates']['indexes'])->pluck('url')->all())->toBe(['https://www.example.org/news/'])
        ->and($result['candidates']['indexes'][0]['same_host_links'])->toBeGreaterThanOrEqual(13)
        ->and($result['candidates']['pagination'])->toBe([['from' => 'https://www.example.org/news/', 'to' => 'https://www.example.org/news/?page=2']])
        ->and($result['candidates']['pdfs'])->toBe(['https://www.example.org/files/annual-report.pdf'])
        ->and($result['candidates']['external_hosts'])->toBe(['partner.example.net' => 1])
        ->and($result['stopped_reason'])->toBe('frontier_empty')
        // Depth 1 for pages; the sitemap index's children are verified one level deeper by design.
        ->and($result['budget']['depth_reached'])->toBe(2);
});

it('never leaves the allowed hosts, obeys robots.txt and records refusals as failures', function () {
    fakeOfficialSite();

    $result = discover();

    $failures = collect($result['failures']);
    expect($failures->firstWhere('url', 'https://www.example.org/private/board')['code'])->toBe('ROBOTS_DISALLOWED')
        ->and($failures->where('code', 'CLIENT_ERROR')->pluck('relation')->unique()->values()->all())->toEqualCanonicalizing(['probe', 'sitemap']);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'partner.example.net') || str_contains($request->url(), '/private/'));
    // Depth 1: the news items linked from /news/ are not fetched.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/news/item-'));
});

it('stops at the URL budget and reports what was left', function () {
    fakeOfficialSite();

    $result = discover(['max_urls' => 3]);

    expect($result['budget']['urls_fetched'])->toBe(3)
        ->and($result['stopped_reason'])->toBe('budget_exhausted')
        ->and($result['budget']['frontier_remaining'])->toBeGreaterThan(0)
        ->and(count($result['resources']))->toBe(3);
});

it('persists every fetched resource for the source with the run as first sighting', function () {
    fakeOfficialSite();

    $result = discover();

    expect(DiscoveredResource::query()->where('source_id', $this->source->id)->count())->toBe(count($result['resources']))
        ->and(DiscoveredResource::query()->where('normalized_url', 'https://www.example.org/feed.xml')->sole())->toMatchArray(['relation' => 'feed', 'first_seen_run_id' => $this->run->id]);
});

it('is deterministic for the same site', function () {
    fakeOfficialSite();
    $first = discover();

    fakeOfficialSite();
    DiscoveredResource::query()->delete();
    $second = discover();

    unset($first['budget']['seconds_used'], $second['budget']['seconds_used']);
    expect($second)->toBe($first);
});
