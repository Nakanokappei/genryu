<?php

namespace App\Actions;

use App\Crawl\Crawler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * robots.txt (RFC 9309), checked before every fetch (cached per host for
 * an hour): a 4xx allows everything, a 5xx or no answer nothing; our
 * token's group wins over "*", the longest matching rule wins (Allow on a
 * tie), and Crawl-delay is honoured.
 */
class RobotsPolicy
{
    /** Our User-agent product token. */
    public const TOKEN = 'Genryu';

    /** How long a robots.txt is cached. */
    private const CACHE_SECONDS = 3600;

    /** Whether robots.txt allows fetching the URL. */
    public function allows(string $url): bool
    {
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $robots = $this->robots($url);

        // Unavailable (4xx): allowed.
        if ($robots['status'] >= 400 && $robots['status'] < 500) {
            return true;
        }

        // Unreachable (5xx, no answer, anything else unsuccessful): disallowed.
        if ($robots['status'] !== 200) {
            return false;
        }

        return self::decide(self::groupFor($robots['body'])['rules'], $path);
    }

    /** Waits out the host's Crawl-delay since our last request, then records this one. */
    public function waitBefore(string $url): void
    {
        $robots = $this->robots($url);
        $delay = $robots['status'] === 200 ? self::groupFor($robots['body'])['delay'] : 0.0;

        // No delay asked.
        if ($delay <= 0) {
            return;
        }

        $key = 'robots:last:'.strtolower((string) parse_url($url, PHP_URL_HOST));
        $remaining = (float) Cache::get($key, 0.0) + $delay - (float) now()->format('U.u');

        // Sleep for what is left of the delay.
        if ($remaining > 0) {
            Sleep::for($remaining)->seconds();
        }

        Cache::put($key, (float) now()->format('U.u'), (int) ceil($delay) + 60);
    }

    /**
     * The host's robots.txt, cached for CACHE_SECONDS.
     *
     * @return array{status: int, body: string}
     */
    private function robots(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        // No host: treated as unreadable.
        if ($host === '') {
            return ['status' => 0, 'body' => ''];
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');

        /** @var array{status: int, body: string} */
        return Cache::remember('robots:'.$origin, self::CACHE_SECONDS, fn (): array => $this->read($origin.'/robots.txt'));
    }

    /**
     * Fetches a robots.txt; status 0 when it cannot be reached.
     *
     * @return array{status: int, body: string}
     */
    private function read(string $url): array
    {
        try {
            $response = Crawler::client(10)->get($url);
        } catch (\Throwable) {
            return ['status' => 0, 'body' => ''];
        }

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * The rules and Crawl-delay of the group for our token, else of "*".
     *
     * @return array{rules: list<array{allow: bool, path: string}>, delay: float}
     */
    private static function groupFor(string $body): array
    {
        $groups = ['own' => [], 'any' => []];
        $delays = ['own' => 0.0, 'any' => 0.0];
        $current = [];
        $seenRule = true;

        // Parse line by line into the own and any groups.
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            // Blank or not a field.
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            // A User-agent line opens or extends the current group.
            if ($field === 'user-agent') {
                // Consecutive User-agent lines share the rules that follow them.
                if ($seenRule) {
                    $current = [];
                    $seenRule = false;
                }

                $current[] = strtolower($value) === strtolower(self::TOKEN) ? 'own' : ($value === '*' ? 'any' : null);

                continue;
            }

            // A rule or delay applies to the current group.
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
     * Whether the path is allowed: the longest matching rule decides, Allow on a tie; allowed when none match.
     *
     * @param  list<array{allow: bool, path: string}>  $rules
     */
    private static function decide(array $rules, string $path): bool
    {
        $verdict = true;
        $longest = -1;

        foreach ($rules as $rule) {
            // An empty path matches nothing.
            if ($rule['path'] === '') {
                continue;
            }

            if (! self::matches($rule['path'], $path)) {
                continue;
            }

            // Longer wins; of the same length, Allow wins.
            $length = strlen($rule['path']);

            if ($length > $longest || ($length === $longest && $rule['allow'])) {
                $longest = $length;
                $verdict = $rule['allow'];
            }
        }

        return $verdict;
    }

    /** Whether a rule path matches: a prefix, "*" any run, trailing "$" the end. */
    private static function matches(string $rule, string $path): bool
    {
        $pattern = '#^'.str_replace('\*', '.*', preg_quote(rtrim($rule, '$'), '#')).(str_ends_with($rule, '$') ? '$' : '').'#';

        return preg_match($pattern, $path) === 1;
    }
}
