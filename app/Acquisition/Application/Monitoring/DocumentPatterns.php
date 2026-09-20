<?php

namespace App\Acquisition\Application\Monitoring;

/**
 * The profile's document_patterns (ADR-0005) as a matcher: first pattern
 * whose include regex matches and exclude regex does not wins. Invalid
 * regexes never match and are reported so drift review can see them.
 */
final class DocumentPatterns
{
    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param  list<array{include: string, exclude?: string|null, document_type: string, identity?: array{from: string}}>  $patterns
     */
    public function __construct(private array $patterns) {}

    /**
     * @return array{document_type: string, identity_from: string}|null
     */
    public function match(string $url): ?array
    {
        foreach ($this->patterns as $index => $pattern) {
            if (! $this->matches($pattern['include'], $url, "document_patterns[{$index}].include")) {
                continue;
            }

            $exclude = $pattern['exclude'] ?? null;

            if (is_string($exclude) && $exclude !== '' && $this->matches($exclude, $url, "document_patterns[{$index}].exclude")) {
                continue;
            }

            return ['document_type' => $pattern['document_type'], 'identity_from' => $pattern['identity']['from'] ?? 'canonical'];
        }

        return null;
    }

    private function matches(string $regex, string $url, string $where): bool
    {
        $result = @preg_match('~'.str_replace('~', '\~', $regex).'~u', $url);

        if ($result === false) {
            $this->warnings[] = "{$where} is not a valid regular expression.";

            return false;
        }

        return $result === 1;
    }
}
