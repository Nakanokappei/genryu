<?php

use App\Actions\FetchUpdates;
use App\Actions\RobotsPolicy;
use App\Exceptions\RobotsForbidden;
use App\Models\Source;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

it('follows the rules of our own group over the * group, longest match first', function () {
    Http::fake(['www.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /\n\nUser-agent: Genryu\nDisallow: /private/\nAllow: /private/press/\nDisallow: /*.pdf$\n", 200)]);
    $robots = new RobotsPolicy;

    expect($robots->allows('https://www.example.org/news'))->toBeTrue()
        ->and($robots->allows('https://www.example.org/private/board'))->toBeFalse()
        ->and($robots->allows('https://www.example.org/private/press/1'))->toBeTrue()
        ->and($robots->allows('https://www.example.org/files/report.pdf'))->toBeFalse();
    // One robots.txt read per host, cached.
    Http::assertSentCount(1);
});

// RFC 9309 2.3.1.3–2.3.1.4: a 4xx robots.txt is unavailable (everything allowed); a 5xx or no answer is unreachable (nothing allowed).
it('allows everything when robots.txt is unavailable and nothing when it is unreachable', function () {
    Http::fake([
        'missing.example.org/robots.txt' => Http::response('', 404),
        'private.example.org/robots.txt' => Http::response('', 403),
        'login.example.org/robots.txt' => Http::response('', 401),
        'gone.example.org/robots.txt' => Http::response('', 410),
        'down.example.org/robots.txt' => Http::response('', 503),
        'unreachable.example.org/robots.txt' => Http::failedConnection(),
    ]);
    $robots = new RobotsPolicy;

    expect($robots->allows('https://missing.example.org/anything'))->toBeTrue()
        ->and($robots->allows('https://private.example.org/anything'))->toBeTrue()
        ->and($robots->allows('https://login.example.org/anything'))->toBeTrue()
        ->and($robots->allows('https://gone.example.org/anything'))->toBeTrue()
        ->and($robots->allows('https://down.example.org/anything'))->toBeFalse()
        ->and($robots->allows('https://unreachable.example.org/anything'))->toBeFalse();
});

// RFC 9309 2.2.2: when an Allow and a Disallow rule match with the same length, Allow wins, whichever is written first.
it('lets Allow win over a Disallow of the same length', function () {
    Http::fake([
        'first.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /page\nAllow: /page\n", 200),
        'second.example.org/robots.txt' => Http::response("User-agent: *\nAllow: /page\nDisallow: /page\n", 200),
    ]);
    $robots = new RobotsPolicy;

    expect($robots->allows('https://first.example.org/page'))->toBeTrue()
        ->and($robots->allows('https://second.example.org/page'))->toBeTrue();
});

// The rule is a global HTTP middleware: every outgoing request is checked, whoever sends it.
it('refuses any outgoing request that robots.txt forbids, before it is sent', function () {
    Http::fake([
        'www.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /form/\n", 200),
        'www.example.org/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect(fn () => Http::get('https://www.example.org/form/event.php?f=press.html'))->toThrow(RobotsForbidden::class, 'robots.txt');
    expect(Http::get('https://www.example.org/news')->status())->toBe(200);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/form/'));

    $source = Source::factory()->create(['url' => 'https://www.example.org/form/event.php?f=press.html']);
    expect(fn () => app(FetchUpdates::class)($source))->toThrow(RobotsForbidden::class);
});

// arXiv: "Crawl-delay: 15" for everyone. Requests to that host are spaced out; other hosts are not.
it('waits out the Crawl-delay between two requests to the same host', function () {
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo('2026-09-21 12:00:00');
    Http::fake([
        'slow.example.org/robots.txt' => Http::response("User-agent: *\nCrawl-delay: 15\nDisallow: /user\n", 200),
        'fast.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /user\n", 200),
        '*' => Http::response('ok', 200),
    ]);

    Http::get('https://slow.example.org/list/cs.AI/new');
    Sleep::assertNeverSlept();

    Http::get('https://slow.example.org/abs/2609.00001');
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => abs($duration->totalSeconds - 15) < 0.01);

    // The wait advanced the clock, so the third request is again 15 s after the second.
    Http::get('https://slow.example.org/abs/2609.00002');
    Sleep::assertSleptTimes(2);

    Http::get('https://fast.example.org/abs/1');
    Http::get('https://fast.example.org/abs/2');
    Sleep::assertSleptTimes(2);
});

it('takes the Crawl-delay of our own group over the * group', function () {
    Sleep::fake(syncWithCarbon: true);
    Http::fake([
        'www.example.org/robots.txt' => Http::response("User-agent: *\nCrawl-delay: 30\n\nUser-agent: Genryu\nCrawl-delay: 2\n", 200),
        '*' => Http::response('ok', 200),
    ]);

    Http::get('https://www.example.org/a');
    Http::get('https://www.example.org/b');

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => abs($duration->totalSeconds - 2) < 0.01);
});

it('exempts robots.txt itself and the API hosts we call as a client', function () {
    Http::fake(['api.openai.com/*' => Http::response(['ok' => true])]);

    expect(Http::post('https://api.openai.com/v1/chat/completions', [])->json('ok'))->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/robots.txt'));
});

it('skips a well-known feed probe that robots.txt forbids instead of failing', function () {
    Http::fake([
        'www.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /rss.xml\n", 200),
        'www.example.org/feed' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><title>t</title><item><title>One</title><link>https://www.example.org/news/1</link></item></channel></rss>', 200, ['Content-Type' => 'application/rss+xml']),
        'www.example.org/*' => Http::response('<html><body>list</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/news']);

    expect(app(FetchUpdates::class)($source)['feed_url'])->toBe('https://www.example.org/feed');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/rss.xml'));
});
