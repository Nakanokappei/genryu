<?php

namespace App\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * robots.txt, honoured before every fetch of a site. The file is read once
 * per host per hour; a missing file (404) allows everything, an unreadable
 * one (error, 5xx) allows nothing until it can be read. Rules for our own
 * product token win over the "*" group; the longest matching rule wins.
 */
class RobotsPolicy
{
    public const TOKEN = 'TechnologyWatch';

    private const CACHE_SECONDS = 3600;

    public function allows(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return false;
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        /** @var array{status: int, body: string} $robots */
        $robots = Cache::remember('robots:'.$origin, self::CACHE_SECONDS, fn (): array => $this->read($origin.'/robots.txt'));

        if ($robots['status'] === 404) {
            return true;
        }

        if ($robots['status'] !== 200) {
            return false;
        }

        return self::decide(self::rulesFor($robots['body']), $path);
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
     * The Allow / Disallow rules of the group that applies to us: the group
     * naming our token when there is one, else the "*" group.
     *
     * @return list<array{allow: bool, path: string}>
     */
    private static function rulesFor(string $body): array
    {
        $groups = ['own' => [], 'any' => []];
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

            if ($field === 'allow' || $field === 'disallow') {
                $seenRule = true;

                foreach (array_unique(array_filter($current)) as $group) {
                    $groups[$group][] = ['allow' => $field === 'allow', 'path' => $value];
                }
            }
        }

        return $groups['own'] !== [] ? $groups['own'] : $groups['any'];
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
