<?php

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\Http\FetchRequest;
use App\Acquisition\Tools\Http\HostThrottle;
use App\Acquisition\Tools\Http\PolicyFetcher;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    $this->context = new ToolContext(1, 'policy');
});

/**
 * A request for a path on www.example.org with the host allowed.
 */
function policyRequest(string $path): FetchRequest
{
    return new FetchRequest('https://www.example.org'.$path, ['www.example.org'], null, null, 10, 1024 * 1024, 5, 1);
}

it('refuses URLs that robots.txt disallows and never requests them', function () {
    Http::fake([
        'www.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /secret/", 200, ['Content-Type' => 'text/plain']),
        'www.example.org/*' => Http::response('page', 200, ['Content-Type' => 'text/html']),
    ]);
    $fetcher = app(PolicyFetcher::class);

    expect(fn () => $fetcher->fetch(policyRequest('/secret/plan'), 60, $this->context))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::RobotsDisallowed));

    $result = $fetcher->fetch(policyRequest('/public/page'), 60, $this->context);

    expect($result->body)->toBe('page');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/secret/'));
    // robots.txt is fetched once per host, not once per URL.
    Http::assertSentCount(2);
});

it('allows everything when robots.txt is absent', function () {
    Http::fake([
        'www.example.org/robots.txt' => Http::response('', 404),
        'www.example.org/*' => Http::response('page', 200),
    ]);

    expect(app(PolicyFetcher::class)->fetch(policyRequest('/any'), 60, $this->context)->status)->toBe(200);
});

it('allows nothing while robots.txt is unreachable', function () {
    Http::fake([
        'www.example.org/robots.txt' => Http::response('', 503),
        'www.example.org/*' => Http::response('page', 200),
    ]);

    expect(fn () => app(PolicyFetcher::class)->fetch(policyRequest('/any'), 60, $this->context))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::RobotsDisallowed));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/any'));
});

it('paces requests to the same host at the configured rate', function () {
    $throttle = app(HostThrottle::class);

    $throttle->await('www.example.org', 30);   // first request: no wait
    $throttle->await('www.example.org', 30);   // second within 2s: waits the remainder
    $throttle->await('other.example.org', 30); // different host: independent budget

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn (CarbonInterval $d): bool => $d->totalMilliseconds > 0 && $d->totalMilliseconds <= 2000);
});
