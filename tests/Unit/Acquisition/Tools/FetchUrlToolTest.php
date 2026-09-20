<?php

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\Http\FetchResult;
use App\Acquisition\Tools\Http\FetchUrlTool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->tool = app(FetchUrlTool::class);
    $this->context = new ToolContext(1, 'test-correlation');
});

/**
 * Run fetch_url with the given payload merged over sane defaults.
 *
 * @param  array<string, mixed>  $overrides
 */
function fetchWith(array $overrides = []): FetchResult
{
    $payload = array_replace_recursive([
        'url' => 'https://www.example.org/news/',
        'allowed_hosts' => ['www.example.org'],
        'limits' => ['max_attempts' => 3],
    ], $overrides);

    /** @var FetchResult $result */
    $result = test()->tool->run(test()->tool->parseRequest($payload), test()->context);

    return $result;
}

/**
 * Expect fetch_url to fail with a specific error code.
 *
 * @param  array<string, mixed>  $overrides
 */
function expectFetchError(ErrorCode $code, array $overrides = []): ToolError
{
    try {
        fetchWith($overrides);
    } catch (ToolError $error) {
        expect($error->errorCode)->toBe($code);

        return $error;
    }

    test()->fail("Expected ToolError {$code->value}, but the fetch succeeded.");
}

it('returns the bytes, selected headers and media types of a successful fetch', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');
    Http::fake(['www.example.org/*' => Http::response($fixture['body'], 200, [
        'Content-Type' => 'text/html; charset=utf-8',
        'ETag' => '"v1"',
        'Set-Cookie' => 'session=secret',
    ])]);

    $result = fetchWith();

    expect($result->status)->toBe(200)
        ->and($result->body)->toBe($fixture['body'])
        ->and($result->sha256())->toBe(hash('sha256', $fixture['body']))
        ->and($result->declaredMediaType)->toBe('text/html')
        ->and($result->detectedMediaType)->toBe('text/html')
        ->and($result->contentTypeMismatch)->toBeFalse()
        ->and($result->etag())->toBe('"v1"')
        ->and($result->headers)->not->toHaveKey('set-cookie')
        ->and($result->attempts)->toBe(1)
        ->and($result->toArray())->not->toHaveKey('body')
        ->and($result->toArray()['body_sha256'])->toBe($result->sha256());

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('User-Agent')[0], 'TechnologyWatch/'));
});

it('sends conditional headers and reports 304 as not modified rather than as an error', function () {
    Http::fake(['www.example.org/*' => Http::response('', 304, ['ETag' => '"v1"'])]);

    $result = fetchWith(['conditional_headers' => ['etag' => '"v1"', 'last_modified' => 'Mon, 21 Sep 2026 00:00:00 GMT']]);

    expect($result->notModified)->toBeTrue()
        ->and($result->status)->toBe(304)
        ->and($result->body)->toBe('');

    Http::assertSent(fn (Request $request): bool => $request->header('If-None-Match') === ['"v1"']
        && $request->header('If-Modified-Since') === ['Mon, 21 Sep 2026 00:00:00 GMT']);
});

it('follows redirects within the allowed hosts and records the chain', function () {
    Http::fake([
        'www.example.org/news/' => Http::response('', 301, ['Location' => '/news']),
        'www.example.org/news' => Http::response('', 302, ['Location' => 'https://www.example.org/latest']),
        'www.example.org/latest' => Http::response('<html>final</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $result = fetchWith();

    expect($result->finalUrl)->toBe('https://www.example.org/latest')
        ->and($result->redirectChain)->toBe(['https://www.example.org/news', 'https://www.example.org/latest'])
        ->and($result->body)->toBe('<html>final</html>');
});

// AT-09: a redirect that leaves the allowed hosts is refused, never followed.
it('refuses a redirect to a host outside the allow list', function () {
    Http::fake([
        'www.example.org/*' => Http::response('', 302, ['Location' => 'https://evil.example.net/']),
        'evil.example.net/*' => Http::response('should never be fetched', 200),
    ]);

    $error = expectFetchError(ErrorCode::HostNotAllowed);

    expect($error->isRetryable())->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example.net'));
});

it('refuses to fetch a URL whose host is not allowed in the first place', function () {
    Http::fake();

    expectFetchError(ErrorCode::HostNotAllowed, ['url' => 'https://other.example.org/']);
    Http::assertNothingSent();
});

it('detects redirect loops and too many redirects', function () {
    Http::fake([
        'www.example.org/news/' => Http::response('', 302, ['Location' => '/loop']),
        'www.example.org/loop' => Http::response('', 302, ['Location' => '/news/']),
    ]);
    expectFetchError(ErrorCode::RedirectLoop);

    Http::fake(['www.example.org/*' => Http::response('', 302, ['Location' => '/'.uniqid()])]);
    expectFetchError(ErrorCode::RedirectLoop, ['limits' => ['max_redirects' => 2]]);
});

it('retries 5xx with backoff and succeeds when the server recovers', function () {
    Http::fake(['www.example.org/*' => Http::sequence()
        ->push('', 503)
        ->push('', 502)
        ->push('ok', 200, ['Content-Type' => 'text/plain'])]);

    $result = fetchWith();

    expect($result->body)->toBe('ok')->and($result->attempts)->toBe(3);
    Sleep::assertSleptTimes(2);
});

it('honours Retry-After on 429 and gives up after the attempt budget', function () {
    Http::fake(['www.example.org/*' => Http::response('', 429, ['Retry-After' => '7'])]);

    $error = expectFetchError(ErrorCode::RateLimited);

    expect($error->isRetryable())->toBeTrue()
        ->and($error->retryAfterSeconds)->toBe(7);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 7, times: 2);
    Http::assertSentCount(3);
});

it('does not wait when Retry-After exceeds the configured maximum', function () {
    Http::fake(['www.example.org/*' => Http::response('', 429, ['Retry-After' => '3600'])]);

    expectFetchError(ErrorCode::RateLimited);

    Sleep::assertNeverSlept();
    Http::assertSentCount(1);
});

it('treats other 4xx as a non-retryable client error', function () {
    Http::fake(['www.example.org/*' => Http::response('gone', 404)]);

    $error = expectFetchError(ErrorCode::ClientError);

    expect($error->isRetryable())->toBeFalse()->and($error->details['status'])->toBe(404);
    Sleep::assertNeverSlept();
    Http::assertSentCount(1);
});

it('classifies transport failures into timeout, TLS and connection errors', function (string $message, ErrorCode $expected) {
    Http::fake(fn () => throw new ConnectionException($message));

    $error = expectFetchError($expected, ['limits' => ['max_attempts' => 1]]);

    expect($error->getPrevious())->toBeInstanceOf(ConnectionException::class);
})->with([
    ['cURL error 28: Operation timed out after 20001 milliseconds', ErrorCode::Timeout],
    ['cURL error 60: SSL certificate problem: unable to get local issuer certificate', ErrorCode::TlsError],
    ['cURL error 7: Failed to connect to www.example.org port 443', ErrorCode::ConnectionFailed],
]);

it('rejects bodies over the limit, whether announced by Content-Length or discovered while streaming', function () {
    Http::fake(['www.example.org/*' => Http::response('x', 200, ['Content-Length' => '99999999'])]);
    expectFetchError(ErrorCode::BodyTooLarge, ['limits' => ['max_body_bytes' => 1024]]);

    Http::fake(['www.example.org/*' => Http::response(str_repeat('a', 2048), 200)]);
    $error = expectFetchError(ErrorCode::BodyTooLarge, ['limits' => ['max_body_bytes' => 1024]]);

    expect($error->isRetryable())->toBeFalse();
});

it('flags a content type that does not match the bytes', function () {
    Http::fake(['www.example.org/*' => Http::response('%PDF-1.7 fake pdf body', 200, ['Content-Type' => 'text/html'])]);

    $result = fetchWith();

    expect($result->declaredMediaType)->toBe('text/html')
        ->and($result->detectedMediaType)->toBe('application/pdf')
        ->and($result->contentTypeMismatch)->toBeTrue();
});

it('rejects malformed requests before touching the network', function (array $payload) {
    Http::fake();

    expect(fn () => $this->tool->parseRequest($payload))->toThrow(function (ToolError $error) {
        expect($error->errorCode)->toBe(ErrorCode::InvalidInput)
            ->and($error->details)->toHaveKey('violations');
    });
    Http::assertNothingSent();
})->with([
    'missing hosts' => [['url' => 'https://www.example.org/']],
    'ftp scheme' => [['url' => 'ftp://www.example.org/', 'allowed_hosts' => ['www.example.org']]],
    'bad limit' => [['url' => 'https://www.example.org/', 'allowed_hosts' => ['www.example.org'], 'limits' => ['max_attempts' => 99]]],
]);
