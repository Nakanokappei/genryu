<?php

use App\Actions\FetchUpdates;
use App\Actions\RobotsPolicy;
use App\Exceptions\RobotsForbidden;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

it('follows the rules of our own group over the * group, longest match first', function () {
    Http::fake(['www.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /\n\nUser-agent: TechnologyWatch\nDisallow: /private/\nAllow: /private/press/\nDisallow: /*.pdf$\n", 200)]);
    $robots = new RobotsPolicy;

    expect($robots->allows('https://www.example.org/news'))->toBeTrue()
        ->and($robots->allows('https://www.example.org/private/board'))->toBeFalse()
        ->and($robots->allows('https://www.example.org/private/press/1'))->toBeTrue()
        ->and($robots->allows('https://www.example.org/files/report.pdf'))->toBeFalse();
    // One robots.txt read per host, cached.
    Http::assertSentCount(1);
});

it('allows everything without a robots.txt and nothing while it cannot be read', function () {
    Http::fake([
        'missing.example.org/robots.txt' => Http::response('', 404),
        'down.example.org/robots.txt' => Http::response('', 503),
    ]);
    $robots = new RobotsPolicy;

    expect($robots->allows('https://missing.example.org/anything'))->toBeTrue()
        ->and($robots->allows('https://down.example.org/anything'))->toBeFalse();
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
