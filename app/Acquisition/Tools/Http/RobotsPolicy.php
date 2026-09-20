<?php

namespace App\Acquisition\Tools\Http;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Log;

/**
 * Answers "may we fetch this URL?" from the host's robots.txt, fetched once
 * per host per instance through the regular Fetch Tool (same host policy,
 * same limits). A missing robots.txt allows everything; an unreachable one
 * is treated as "disallow all" until it can be read, the conservative
 * reading of the standard.
 */
final class RobotsPolicy
{
    /** @var array<string, RobotsRules|null> host => rules, null = unreachable */
    private array $cache = [];

    public function __construct(private FetchUrlTool $fetch) {}

    /**
     * @param  list<string>  $allowedHosts
     */
    public function isAllowed(string $url, array $allowedHosts, ToolContext $context): bool
    {
        $uri = new Uri($url);
        $host = strtolower($uri->getHost());
        $rules = $this->rulesFor($uri->getScheme() ?: 'https', $host, $allowedHosts, $context);

        if ($rules === null) {
            return false;
        }

        $path = $uri->getPath() === '' ? '/' : $uri->getPath();

        if ($uri->getQuery() !== '') {
            $path .= '?'.$uri->getQuery();
        }

        return $rules->allows($path, (string) config('acquisition.robots_token'));
    }

    /**
     * Sitemap URLs advertised by the host's robots.txt (empty when none or unreachable).
     *
     * @param  list<string>  $allowedHosts
     * @return list<string>
     */
    public function sitemaps(string $url, array $allowedHosts, ToolContext $context): array
    {
        $uri = new Uri($url);

        return $this->rulesFor($uri->getScheme() ?: 'https', strtolower($uri->getHost()), $allowedHosts, $context)?->sitemaps() ?? [];
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function rulesFor(string $scheme, string $host, array $allowedHosts, ToolContext $context): ?RobotsRules
    {
        if (array_key_exists($host, $this->cache)) {
            return $this->cache[$host];
        }

        $robotsUrl = "{$scheme}://{$host}/robots.txt";

        try {
            /** @var FetchResult $result */
            $result = $this->fetch->run(new FetchRequest($robotsUrl, $allowedHosts, null, null, 10, 512 * 1024, 3, 2), $context);
            $rules = RobotsRules::parse($result->body);
        } catch (ToolError $error) {
            // 4xx (typically 404) means "no robots.txt": everything allowed.
            if ($error->errorCode === ErrorCode::ClientError) {
                $rules = RobotsRules::parse('');
            } else {
                Log::channel('acquisition')->warning('acquisition.robots.unreachable', ['host' => $host, 'code' => $error->errorCode->value, 'run_id' => $context->runId]);
                $rules = null;
            }
        }

        return $this->cache[$host] = $rules;
    }
}
