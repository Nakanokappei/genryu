<?php

namespace App\Acquisition\Tools\Http;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Identity\UrlNormalizer;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;
use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * HTTP Fetch Tool (plan §7.2). Fetches one resource byte-for-byte, follows
 * redirects itself so every hop is re-checked against the allowed hosts,
 * caps body size while streaming, and retries only the transient failures
 * defined in ADR-0004. Never interprets the body.
 */
final class FetchUrlTool implements Tool
{
    /**
     * Response headers worth keeping on the fetch observation. Everything
     * else (cookies in particular) is dropped before it can be stored.
     */
    private const KEPT_HEADERS = [
        'content-type', 'content-length', 'content-language', 'etag', 'last-modified',
        'cache-control', 'expires', 'retry-after', 'location',
    ];

    /**
     * Base delays for attempts 1, 2, 3+ before jitter (ADR-0004).
     */
    private const BACKOFF_SECONDS = [1, 4, 16];

    public function __construct(private HttpFactory $http) {}

    public function name(): string
    {
        return 'fetch_url';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        return FetchRequest::fromArray($payload);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof FetchRequest) {
            throw new ToolError(ErrorCode::InvalidInput, 'fetch_url expects a FetchRequest.');
        }

        $attempt = 0;

        // Retry loop: only retryable codes come back here, and only within
        // the attempt budget. Waiting goes through Sleep so tests can fake it.
        while (true) {
            $attempt++;

            try {
                return $this->attempt($request, $attempt);
            } catch (ToolError $error) {
                if (! $error->isRetryable() || $attempt >= $request->maxAttempts) {
                    throw $error;
                }

                $delay = $this->retryDelay($error, $attempt);

                if ($delay === null) {
                    throw $error;
                }

                Sleep::for($delay)->seconds();
            }
        }
    }

    /**
     * One full fetch including its redirect chain.
     */
    private function attempt(FetchRequest $request, int $attempt): FetchResult
    {
        $startedAt = hrtime(true);
        $retrievedAt = CarbonImmutable::now('UTC');
        $url = $request->url;
        $chain = [];

        $this->assertHostAllowed($url, $request);

        while (true) {
            $response = $this->send($url, $request);
            $status = $response->status();

            // Redirects are followed manually so the host policy applies to
            // every hop and loops are caught (AT-09).
            if ($status >= 300 && $status < 400 && $status !== 304) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new ToolError(ErrorCode::ClientError, "Redirect {$status} without a Location header.", ['url' => $url, 'status' => $status]);
                }

                $next = UrlNormalizer::resolve($url, $location);

                if ($next === $request->url || in_array($next, $chain, true)) {
                    throw new ToolError(ErrorCode::RedirectLoop, 'Redirect loop detected.', ['chain' => [...$chain, $next]]);
                }

                if (count($chain) >= $request->maxRedirects) {
                    throw new ToolError(ErrorCode::RedirectLoop, "More than {$request->maxRedirects} redirects.", ['chain' => [...$chain, $next]]);
                }

                $this->assertHostAllowed($next, $request);
                $chain[] = $next;
                $url = $next;

                continue;
            }

            $headers = $this->keptHeaders($response);
            $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

            if ($status === 304) {
                return new FetchResult($request->url, $url, $chain, 304, $headers, null, null, false, $retrievedAt, $durationMs, $attempt, '', true);
            }

            if ($status === 429) {
                throw new ToolError(ErrorCode::RateLimited, "429 from {$url}.", ['url' => $url, 'status' => 429], $this->retryAfterSeconds($response));
            }

            if ($status >= 500) {
                throw new ToolError(ErrorCode::ServerError, "{$status} from {$url}.", ['url' => $url, 'status' => $status], $this->retryAfterSeconds($response));
            }

            if ($status >= 400) {
                throw new ToolError(ErrorCode::ClientError, "{$status} from {$url}.", ['url' => $url, 'status' => $status]);
            }

            $body = $this->readBody($response, $request->maxBodyBytes, $url);
            $declared = $this->declaredMediaType($response);
            $detected = $body === '' ? null : ((new finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: null);

            return new FetchResult(
                requestedUrl: $request->url,
                finalUrl: $url,
                redirectChain: $chain,
                status: $status,
                headers: $headers,
                declaredMediaType: $declared,
                detectedMediaType: $detected,
                contentTypeMismatch: $this->isMismatch($declared, $detected),
                retrievedAt: $retrievedAt,
                durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
                attempts: $attempt,
                body: $body,
                notModified: false,
            );
        }
    }

    /**
     * Issue one request without following redirects, translating transport
     * failures into the error taxonomy.
     */
    private function send(string $url, FetchRequest $request): Response
    {
        $headers = ['User-Agent' => config('acquisition.user_agent')];

        if ($request->ifNoneMatch !== null) {
            $headers['If-None-Match'] = $request->ifNoneMatch;
        }

        if ($request->ifModifiedSince !== null) {
            $headers['If-Modified-Since'] = $request->ifModifiedSince;
        }

        try {
            return $this->http
                ->withHeaders($headers)
                ->withoutRedirecting()
                ->timeout($request->timeoutSeconds)
                ->connectTimeout(min(10, $request->timeoutSeconds))
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw $this->classifyConnectionFailure($exception, $url);
        }
    }

    /**
     * Laravel raises one exception type for every transport failure; the
     * message is the only place cURL tells us which kind it was.
     */
    private function classifyConnectionFailure(ConnectionException $exception, string $url): ToolError
    {
        $message = strtolower($exception->getMessage());
        $details = ['url' => $url];

        if (str_contains($message, 'timed out') || str_contains($message, 'curl error 28')) {
            return new ToolError(ErrorCode::Timeout, "Timed out fetching {$url}.", $details, null, $exception);
        }

        if (str_contains($message, 'ssl') || str_contains($message, 'certificate') || str_contains($message, 'curl error 35') || str_contains($message, 'curl error 60')) {
            return new ToolError(ErrorCode::TlsError, "TLS failure fetching {$url}.", $details, null, $exception);
        }

        return new ToolError(ErrorCode::ConnectionFailed, "Could not connect to {$url}.", $details, null, $exception);
    }

    /**
     * Read the body in chunks, giving up as soon as the cap is exceeded so a
     * hostile or misconfigured server cannot exhaust memory.
     */
    private function readBody(Response $response, int $maxBodyBytes, string $url): string
    {
        $declaredLength = (int) $response->header('Content-Length');

        if ($declaredLength > $maxBodyBytes) {
            throw new ToolError(ErrorCode::BodyTooLarge, "Content-Length {$declaredLength} exceeds limit {$maxBodyBytes}.", ['url' => $url, 'content_length' => $declaredLength, 'max_body_bytes' => $maxBodyBytes]);
        }

        $stream = $response->toPsrResponse()->getBody();

        // A live response stream starts at 0; a reused (faked) one may not.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(65536);

            if (strlen($body) > $maxBodyBytes) {
                $stream->close();

                throw new ToolError(ErrorCode::BodyTooLarge, "Body exceeds limit {$maxBodyBytes} bytes.", ['url' => $url, 'max_body_bytes' => $maxBodyBytes]);
            }
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    private function keptHeaders(Response $response): array
    {
        $kept = [];

        foreach ($response->headers() as $name => $values) {
            $lower = strtolower($name);

            if (in_array($lower, self::KEPT_HEADERS, true) && $values !== []) {
                $kept[$lower] = implode(', ', $values);
            }
        }

        ksort($kept);

        return $kept;
    }

    private function declaredMediaType(Response $response): ?string
    {
        $header = $response->header('Content-Type');

        if ($header === '') {
            return null;
        }

        return strtolower(trim(explode(';', $header, 2)[0]));
    }

    /**
     * Declared and detected types disagree only when both belong to a known
     * family and the families differ; finfo's vague fallbacks don't count.
     */
    private function isMismatch(?string $declared, ?string $detected): bool
    {
        $declaredFamily = self::family($declared);
        $detectedFamily = self::family($detected);

        return $declaredFamily !== null && $detectedFamily !== null && $declaredFamily !== $detectedFamily;
    }

    private static function family(?string $mediaType): ?string
    {
        return match ($mediaType) {
            'text/html', 'application/xhtml+xml' => 'html',
            'text/xml', 'application/xml', 'application/rss+xml', 'application/atom+xml' => 'xml',
            'application/pdf' => 'pdf',
            'application/json', 'application/ld+json' => 'json',
            default => null,
        };
    }

    /**
     * Parse Retry-After, which may be seconds or an HTTP date.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            $seconds = CarbonImmutable::now('UTC')->diffInSeconds(CarbonImmutable::parse($value));
        } catch (InvalidArgumentException) {
            return null;
        }

        return (int) max(0, $seconds);
    }

    /**
     * Seconds to wait before the next attempt, or null when the server asks
     * for longer than we are willing to wait (ADR-0004).
     */
    private function retryDelay(ToolError $error, int $attempt): ?float
    {
        if ($error->retryAfterSeconds !== null) {
            return $error->retryAfterSeconds > (int) config('acquisition.fetch.max_retry_after_seconds')
                ? null
                : (float) $error->retryAfterSeconds;
        }

        $base = self::BACKOFF_SECONDS[min($attempt, count(self::BACKOFF_SECONDS)) - 1];

        // ±25% jitter keeps many sources from retrying in lockstep.
        return $base * random_int(75, 125) / 100;
    }

    private function assertHostAllowed(string $url, FetchRequest $request): void
    {
        try {
            $host = UrlNormalizer::host($url);
        } catch (InvalidArgumentException) {
            throw new ToolError(ErrorCode::InvalidInput, "Not an absolute http(s) URL: {$url}", ['url' => $url]);
        }

        if ($host === null || ! in_array($host, $request->allowedHosts, true)) {
            throw new ToolError(ErrorCode::HostNotAllowed, "Host of {$url} is not in the allowed hosts.", ['url' => $url, 'allowed_hosts' => $request->allowedHosts]);
        }
    }
}
