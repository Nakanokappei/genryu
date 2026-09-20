<?php

namespace App\Acquisition\Tools\Http;

/**
 * A parsed robots.txt (plan §0, §5.1). Group selection follows the usual
 * rules: the group whose User-agent token appears in our token wins, else
 * "*", else everything is allowed. Within a group the longest matching
 * path wins, and Allow beats Disallow on equal length.
 */
final class RobotsRules
{
    /**
     * @param  array<string, list<array{allow: bool, path: string}>>  $groups  lower-cased token => rules
     * @param  list<string>  $sitemaps  absolute URLs from Sitemap: directives
     */
    private function __construct(private array $groups, private array $sitemaps = []) {}

    public static function parse(string $robotsTxt): self
    {
        $groups = [];
        $sitemaps = [];
        $currentTokens = [];
        $lastWasUserAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $robotsTxt) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // Consecutive User-agent lines share one group of rules.
                if (! $lastWasUserAgent) {
                    $currentTokens = [];
                }

                $token = strtolower($value);
                $currentTokens[] = $token;
                $groups[$token] ??= [];
                $lastWasUserAgent = true;

                continue;
            }

            $lastWasUserAgent = false;

            // Sitemap directives are global, not part of any group.
            if ($field === 'sitemap' && $value !== '') {
                $sitemaps[] = $value;

                continue;
            }

            if (($field === 'allow' || $field === 'disallow') && $currentTokens !== []) {
                foreach ($currentTokens as $token) {
                    $groups[$token][] = ['allow' => $field === 'allow', 'path' => $value];
                }
            }
        }

        return new self($groups, array_values(array_unique($sitemaps)));
    }

    /**
     * Sitemap URLs the file advertises, in order of appearance.
     *
     * @return list<string>
     */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    /**
     * Whether $path (with query) may be fetched by the given product token.
     */
    public function allows(string $path, string $token): bool
    {
        $rules = $this->groupFor(strtolower($token));

        if ($rules === null) {
            return true;
        }

        $decision = true;
        $longest = -1;

        foreach ($rules as $rule) {
            // An empty Disallow means "allow everything" and matches nothing.
            if ($rule['path'] === '') {
                continue;
            }

            if (self::matches($rule['path'], $path)) {
                $length = strlen($rule['path']);

                if ($length > $longest || ($length === $longest && $rule['allow'])) {
                    $longest = $length;
                    $decision = $rule['allow'];
                }
            }
        }

        return $decision;
    }

    /**
     * @return list<array{allow: bool, path: string}>|null
     */
    private function groupFor(string $token): ?array
    {
        foreach ($this->groups as $groupToken => $rules) {
            if ($groupToken !== '*' && str_contains($token, $groupToken)) {
                return $rules;
            }
        }

        return $this->groups['*'] ?? null;
    }

    /**
     * robots.txt patterns: prefix match with "*" wildcards and an optional
     * trailing "$" anchor.
     */
    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = implode('.*', array_map('preg_quote', explode('*', rtrim($pattern, '$'))));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
