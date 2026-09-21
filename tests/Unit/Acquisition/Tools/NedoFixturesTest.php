<?php

use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Normalize\SourceContext;
use Carbon\CarbonImmutable;

// Real NEDO captures (tests/Fixtures/Acquisition/nedo): the same generic
// parsers must handle a Japanese site without <main>, canonical URLs, date
// metadata or ETags (AT-15). Nothing here is NEDO-specific code.

it('extracts the Japanese title, language and printed date from a real NEDO press release', function () {
    $fixture = acquisitionFixture('nedo/press-release');

    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);

    expect($parsed->title)->toBe('アンモニア燃料によるナフサ分解炉運転で世界最高水準の混焼率85％を達成しました | ニュース | NEDO')
        ->and($parsed->language)->toBe('ja')
        ->and($parsed->canonicalUrl)->toBeNull()
        // No <main> or <article>: the body is used after navigation and footer are stripped.
        ->and($parsed->mainContentSelector)->toBe('body')
        ->and($parsed->quality['text_characters'])->toBeGreaterThan(2000)
        ->and($parsed->markdown)->toContain('## 1．背景')
        ->and($parsed->markdown)->toContain('/content/100942724.pdf')
        ->and($parsed->markdown)->not->toContain('人気ワード')
        // The only date is the 年月日 text under the heading.
        ->and(collect($parsed->dateCandidates)->firstWhere('source', 'text:leading'))->toMatchArray(['kind' => 'published', 'value' => '2026-09-17T00:00:00Z', 'raw' => '2026年9月17日', 'confidence' => 0.4]);
});

it('normalizes the press release once the profile stops requiring a canonical URL', function () {
    $fixture = acquisitionFixture('nedo/press-release');
    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);
    $context = new SourceContext('nedo', $fixture['meta']['url'], $fixture['meta']['final_url'], CarbonImmutable::parse($fixture['meta']['retrieved_at']), 'text/html', hash('sha256', $fixture['body']), null, null, 'press_release', requiredFields: ['title']);

    $document = (new NormalizeDocumentTool)->normalize($parsed, $context);

    expect($document->stableKey)->toBe('url:https://www.nedo.go.jp/news/press/AA5_101965.html')
        ->and($document->identityRule)->toBe('url')
        ->and($document->publishedAt)->toBe('2026-09-17T00:00:00Z')
        ->and($document->language)->toBe('ja')
        ->and($document->quality['passed'])->toBeTrue()
        ->and($document->toMarkdown())->toContain('title: "アンモニア燃料');
});

it('reads the real NEDO news index as a list of dated press-release links', function () {
    $fixture = acquisitionFixture('nedo/news-index');

    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);
    $pressLinks = collect($parsed->links)->pluck('url')->filter(fn (string $url): bool => str_starts_with($url, 'https://www.nedo.go.jp/news/press/AA5_'));

    expect($parsed->title)->toBe('ニュース | NEDO')
        ->and($pressLinks->count())->toBe(10)
        ->and(collect($parsed->dateCandidates)->firstWhere('source', 'time[datetime]')['value'])->toBe('2026-09-17T00:00:00Z');
});
