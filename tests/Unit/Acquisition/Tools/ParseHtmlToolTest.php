<?php

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\Html\ParsedHtml;
use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\ToolError;

beforeEach(function () {
    $this->tool = new ParseHtmlTool;
});

it('extracts title, canonical URL, metadata, JSON-LD, author and language from a well-formed article', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');

    $parsed = $this->tool->parse($fixture['body'], $fixture['meta']['final_url']);

    expect($parsed->parserId)->toBe('html.generic@1')
        ->and($parsed->title)->toBe('Example Agency Announces New Research Program')
        ->and($parsed->canonicalUrl)->toBe('https://www.example.org/news/simple-article')
        ->and($parsed->language)->toBe('en')
        ->and($parsed->author)->toBe('Example Agency')
        ->and($parsed->openGraph)->toHaveKey('og:url')
        ->and($parsed->jsonLd)->toHaveCount(1)
        ->and($parsed->jsonLd[0]['@type'])->toBe('NewsArticle')
        ->and($parsed->mainContentSelector)->toBe('main article')
        ->and($parsed->warnings)->toBeEmpty();
});

it('keeps every date as a candidate with source and confidence instead of guessing one', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');

    $parsed = $this->tool->parse($fixture['body'], $fixture['meta']['final_url']);
    $sources = array_column($parsed->dateCandidates, 'source');

    expect($sources)->toContain('meta:article:published_time', 'json-ld[0].datePublished', 'time[datetime]')
        ->and($parsed->dateCandidates[0]['confidence'])->toBeGreaterThanOrEqual($parsed->dateCandidates[1]['confidence'])
        ->and(collect($parsed->dateCandidates)->firstWhere('source', 'meta:article:published_time')['value'])->toBe('2026-09-20T09:00:00Z')
        ->and(collect($parsed->dateCandidates)->firstWhere('source', 'time[datetime]'))->toMatchArray(['kind' => 'published', 'confidence' => 0.6]);
});

it('produces Markdown of the main content only, with absolute links and no navigation', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');

    $parsed = $this->tool->parse($fixture['body'], $fixture['meta']['final_url']);

    expect($parsed->markdown)->toStartWith('# Example Agency Announces New Research Program')
        ->and($parsed->markdown)->toContain('Technical Area 1 focuses on deterministic extraction')
        ->and($parsed->markdown)->not->toContain('Home')
        ->and($parsed->markdown)->not->toContain('Example Agency</p>')
        ->and(array_column($parsed->links, 'url'))->toBe([
            'https://www.example.org/programs/fixture-parsing',
            'https://www.example.org/files/fixture-parsing-baa.pdf',
        ])
        ->and($parsed->headings)->toBe([['level' => 1, 'text' => 'Example Agency Announces New Research Program']])
        ->and($parsed->quality['text_characters'])->toBeGreaterThan(400)
        ->and($parsed->quality['has_canonical'])->toBeTrue();
});

// Determinism is part of the contract: same bytes, same version, same output.
it('returns byte-identical output for the same input', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');

    $first = $this->tool->parse($fixture['body'], $fixture['meta']['final_url'])->toArray();
    $second = $this->tool->parse($fixture['body'], $fixture['meta']['final_url'])->toArray();

    expect(json_encode($second))->toBe(json_encode($first));
});

// AT-08 groundwork: a shell page parses "successfully" but with zero content and warnings.
it('reports an empty shell page as zero content with warnings, not as a parse failure', function () {
    $fixture = acquisitionFixture('synthetic/empty-page');

    $parsed = $this->tool->parse($fixture['body'], $fixture['meta']['final_url']);

    expect($parsed->title)->toBeNull()
        ->and($parsed->quality['text_characters'])->toBe(0)
        ->and($parsed->quality['has_title'])->toBeFalse()
        ->and($parsed->warnings)->toContain('No title found.')
        ->and($parsed->warnings)->toContain('No text content in main container.');
});

it('tolerates broken markup, invalid JSON-LD and relative canonicals', function () {
    $html = <<<'HTML'
        <html><head><title>Broken</title>
        <link rel="canonical" href="/relative/path">
        <script type="application/ld+json">{not json</script>
        </head><body><p>Unclosed paragraph<div>and a div<a href="mailto:x@example.org">mail</a>
        <a href="#frag">frag</a><a href="docs/a.html">doc</a></body></html>
        HTML;

    $parsed = $this->tool->parse($html, 'https://www.example.org/news/item');

    expect($parsed->title)->toBe('Broken')
        ->and($parsed->canonicalUrl)->toBe('https://www.example.org/relative/path')
        ->and($parsed->warnings)->toContain('JSON-LD block 0 is not valid JSON.')
        ->and($parsed->warnings)->toContain('No <main> or <article>; using <body>.')
        ->and(array_column($parsed->links, 'url'))->toBe(['https://www.example.org/news/docs/a.html']);
});

it('round-trips through its wire form', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');
    $parsed = $this->tool->parse($fixture['body'], $fixture['meta']['final_url']);

    expect(ParsedHtml::fromArray($parsed->toArray())->toArray())->toBe($parsed->toArray());
});

it('rejects unsupported parser versions and digests the body in the audit form', function () {
    expect(fn () => $this->tool->parseRequest(['html' => '<p>x</p>', 'url' => 'https://www.example.org/', 'parser_version' => 2]))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::InvalidInput));

    $request = $this->tool->parseRequest(['html' => '<p>x</p>', 'url' => 'https://www.example.org/']);

    expect($request->toArray())->toBe(['html_sha256' => hash('sha256', '<p>x</p>'), 'url' => 'https://www.example.org/', 'parser_version' => 1]);
});
