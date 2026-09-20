<?php

namespace App\Acquisition\Tools\Http;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Identity\UrlNormalizer;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;

/**
 * fetch_url wrapped in the crawl policy: robots.txt is consulted and the
 * per-host rate limit is respected before any request leaves. Monitoring
 * and Discovery fetch through this, never through the raw tool.
 */
final class PolicyFetcher
{
    public function __construct(
        private FetchUrlTool $fetch,
        private RobotsPolicy $robots,
        private HostThrottle $throttle,
    ) {}

    /**
     * @throws ToolError with ErrorCode::RobotsDisallowed when robots.txt forbids the URL
     */
    public function fetch(FetchRequest $request, int $requestsPerMinute, ToolContext $context): FetchResult
    {
        $host = UrlNormalizer::host($request->url) ?? '';

        // robots.txt itself goes through the throttle as well.
        $this->throttle->await($host, $requestsPerMinute);

        if (! $this->robots->isAllowed($request->url, $request->allowedHosts, $context)) {
            throw new ToolError(ErrorCode::RobotsDisallowed, "robots.txt disallows {$request->url}.", ['url' => $request->url]);
        }

        $this->throttle->await($host, $requestsPerMinute);

        /** @var FetchResult $result */
        $result = $this->fetch->run($request, $context);

        return $result;
    }
}
