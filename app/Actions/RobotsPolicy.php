<?php

namespace App\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * robots.txt, honoured before every fetch of a site. The file is read once
 * per host per hour; a missing file (404) allows everything, an unreadable
 * one (error, 5xx) allows nothing until it can be read. Rules for our own
 * product token win over the "*" group; the longest matching rule wins.
 * A Crawl-delay in that group is the least time between two of our
 * requests to the host.
 */
class RobotsPolicy
{
    public const TOKEN = 'Genryu';

    private const CACHE_SECONDS = 3600;

    public function allows(string $url): bool
    {
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $robots = $this->robots($url);

        if ($robots['status'] === 404) {
            return true;
        }

        if ($robots['status'] !== 200) {
            return false;
        }

        return self::decide(self::groupFor($robots['body'])['rules'], $path);
    }

    /**
     * Wait until the host's Crawl-delay has passed since our last request
     * to it, then take the slot. Meant to be called right before sending.
     */
    public function waitBefore(string $url): void
    {
        $robots = $this->robots($url);
        $delay = $robots['status'] === 200 ? self::groupFor($robots['body'])['delay'] : 0.0;

        if ($delay <= 0) {
            return;
        }

        $key = 'robots:last:'.strtolower((string) parse_url($url, PHP_URL_HOST));
        $remaining = (float) Cache::get($key, 0.0) + $delay - (float) now()->format('U.u');

        if ($remaining > 0) {
            Sleep::for($remaining)->seconds();
        }

        Cache::put($key, (float) now()->format('U.u'), (int) ceil($delay) + 60);
    }

    /**
     * The host's robots.txt, read once per hour.
     *
     * @return array{status: int, body: string}
     */
    private function robots(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return ['status' => 0, 'body' => ''];
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');

        /** @var array{status: int, body: string} */
        return Cache::remember('robots:'.$origin, self::CACHE_SECONDS, fn (): array => $this->read($origin.'/robots.txt'));
    }

    /**
     * @return array{status: int, body: string}
     */
    private function read(string $url): array
    {
        try {
            $response = Http::withUserAgent(FetchUpdates::USER_AGENT)->timeout(10)->get($url);
        } catch (\Throwable) {
            return ['status' => 0, 'body' => ''];
        }

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * The Allow / Disallow rules and the Crawl-delay (seconds, 0 when none)
     * of the group that applies to us: the group naming our token when
     * there is one, else the "*" group.
     *
     * @return array{rules: list<array{allow: bool, path: string}>, delay: float}
     */
    private static function groupFor(string $body): array
    {
        $groups = ['own' => [], 'any' => []];
        $delays = ['own' => 0.0, 'any' => 0.0];
        $current = [];
        $seenRule = true;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // Consecutive User-agent lines share the rules that follow them.
                if ($seenRule) {
                    $current = [];
                    $seenRule = false;
                }

                $current[] = strtolower($value) === strtolower(self::TOKEN) ? 'own' : ($value === '*' ? 'any' : null);

                continue;
            }

            if ($field === 'allow' || $field === 'disallow' || $field === 'crawl-delay') {
                $seenRule = true;

                foreach (array_unique(array_filter($current)) as $group) {
                    if ($field === 'crawl-delay') {
                        $delays[$group] = max(0.0, (float) $value);
                    } else {
                        $groups[$group][] = ['allow' => $field === 'allow', 'path' => $value];
                    }
                }
            }
        }

        $group = $groups['own'] !== [] || $delays['own'] > 0 ? 'own' : 'any';

        return ['rules' => $groups[$group], 'delay' => $delays[$group]];
    }

    /**
     * @param  list<array{allow: bool, path: string}>  $rules
     */
    private static function decide(array $rules, string $path): bool
    {
        $verdict = true;
        $longest = -1;

        foreach ($rules as $rule) {
            if ($rule['path'] === '') {
                continue;
            }

            if (self::matches($rule['path'], $path) && strlen($rule['path']) > $longest) {
                $longest = strlen($rule['path']);
                $verdict = $rule['allow'];
            }
        }

        return $verdict;
    }

    /**
     * A rule path is a prefix; "*" matches anything and a trailing "$" anchors the end.
     */
    private static function matches(string $rule, string $path): bool
    {
        $pattern = '#^'.str_replace('\*', '.*', preg_quote(rtrim($rule, '$'), '#')).(str_ends_with($rule, '$') ? '$' : '').'#';

        return preg_match($pattern, $path) === 1;
    }
}
