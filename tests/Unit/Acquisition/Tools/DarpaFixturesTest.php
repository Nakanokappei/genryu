<?php

use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Normalize\SourceContext;
use App\Acquisition\Tools\Xml\ParseXmlTool;
use Carbon\CarbonImmutable;

// Real DARPA captures (tests/Fixtures/Acquisition/darpa): the generic
// parsers must cope with a production Drupal site, not only with synthetic pages.

it('parses the real DARPA RSS feed with GUIDs, links and dates', function () {
    $fixture = acquisitionFixture('darpa/rss');

    $parsed = (new ParseXmlTool)->parse($fixture['body'], $fixture['meta']['final_url'], 'rss');

    expect($parsed->kind)->toBe('rss')
        ->and($parsed->feed['title'])->toContain('DARPA')
        ->and($parsed->entries)->toHaveCount(10)
        ->and($parsed->quality)->toMatchArray(['entries_missing_url' => 0, 'entries_missing_id' => 0])
        ->and($parsed->entries[0]['id'])->toMatch('/^\d+ at https:\/\/www\.darpa\.mil$/')
        ->and($parsed->entries[0]['url'])->toStartWith('https://www.darpa.mil/news/')
        ->and($parsed->entries[0]['published'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
});

it('extracts title, canonical URL, dates and readable Markdown from a real DARPA news article', function () {
    $fixture = acquisitionFixture('darpa/news-article');

    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);

    expect($parsed->title)->toContain('trauma')
        ->and($parsed->canonicalUrl)->toBe('https://www.darpa.mil/news/2026/darpa-competition-surgical')
        ->and($parsed->language)->toBe('en')
        ->and($parsed->quality['text_characters'])->toBeGreaterThan(1000)
        ->and($parsed->markdown)->toContain('DARPA')
        ->and($parsed->markdown)->not->toContain('<script')
        // No metadata dates on this site: the leading-text heuristic is the only witness.
        ->and(collect($parsed->dateCandidates)->firstWhere('source', 'text:leading'))->toMatchArray(['kind' => 'published', 'value' => '2026-09-15T00:00:00Z', 'raw' => 'Sept. 15, 2026', 'confidence' => 0.4]);
});

it('normalizes the real article into a document that passes the default quality expectations', function () {
    $fixture = acquisitionFixture('darpa/news-article');
    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);
    $context = new SourceContext('darpa', $fixture['meta']['url'], $fixture['meta']['final_url'], CarbonImmutable::parse($fixture['meta']['retrieved_at']), 'text/html', hash('sha256', $fixture['body']), null, '5501 at https://www.darpa.mil', 'news', feedPublishedAt: '2026-09-14T18:32:21Z');

    $document = (new NormalizeDocumentTool)->normalize($parsed, $context);

    expect($document->stableKey)->toBe('guid:5501 at https://www.darpa.mil')
        ->and($document->canonicalUrl)->toBe('https://www.darpa.mil/news/2026/darpa-competition-surgical')
        // The feed's pubDate (0.8) outranks the page's leading-text date (0.4).
        ->and($document->publishedAt)->toBe('2026-09-14T18:32:21Z')
        ->and(collect($document->provenance['date_candidates'])->pluck('source')->all())->toBe(['feed:published', 'text:leading'])
        ->and($document->quality['passed'])->toBeTrue()
        ->and($document->toMarkdown())->toStartWith("---\nschema_version: 1\nsource: \"darpa\"");
});
