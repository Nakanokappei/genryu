<?php

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\Xml\ParsedXml;
use App\Acquisition\Tools\Xml\ParseXmlTool;

beforeEach(function () {
    $this->tool = new ParseXmlTool;
});

/**
 * Parse a fixture with the tool under test.
 */
function parseXmlFixture(string $name, ?string $expectedKind = null): ParsedXml
{
    $fixture = acquisitionFixture($name);

    return test()->tool->parse($fixture['body'], $fixture['meta']['final_url'], $expectedKind);
}

it('parses RSS 2.0 items with GUIDs, links and dates, using dc:date as a fallback', function () {
    $parsed = parseXmlFixture('synthetic/rss-basic', 'rss');

    expect($parsed->kind)->toBe('rss')
        ->and($parsed->parserId)->toBe('xml.feed@1')
        ->and($parsed->feed['title'])->toBe('Example Agency News')
        ->and($parsed->feed['updated'])->toBe('2026-09-21T08:00:00Z')
        ->and($parsed->entries)->toHaveCount(2)
        ->and($parsed->entries[0])->toMatchArray([
            'id' => 'tag:example.org,2026:news/1001',
            'url' => 'https://www.example.org/news/simple-article?utm_source=rss',
            'published' => '2026-09-20T09:00:00Z',
        ])
        ->and($parsed->entries[1]['id'])->toBeNull()
        ->and($parsed->entries[1]['published'])->toBe('2026-09-18T15:30:00Z')
        ->and($parsed->quality)->toMatchArray(['entry_count' => 2, 'entries_missing_id' => 1, 'entries_missing_url' => 0, 'kind_matches_expected' => true])
        ->and($parsed->warnings)->toBeEmpty();
});

it('parses Atom feeds including the alternate link choice and rel=next pagination', function () {
    $parsed = parseXmlFixture('synthetic/atom-basic');

    expect($parsed->kind)->toBe('atom')
        ->and($parsed->feed['url'])->toBe('https://www.example.org/programs/')
        ->and($parsed->pagination['next'])->toBe('https://www.example.org/atom.xml?page=2')
        ->and($parsed->entries[0])->toMatchArray([
            'id' => 'urn:uuid:5f1c3a2e-1111-4c1e-9d2b-000000000001',
            'url' => 'https://www.example.org/programs/fixture-parsing',
            'published' => '2026-09-20T09:00:00Z',
            'updated' => '2026-09-21T07:00:00Z',
        ])
        ->and($parsed->entries[1]['url'])->toBe('https://www.example.org/programs/provenance')
        ->and($parsed->entries[1]['published'])->toBeNull();
});

it('parses sitemaps and sitemap indexes', function () {
    $sitemap = parseXmlFixture('synthetic/sitemap-basic', 'sitemap');
    $index = parseXmlFixture('synthetic/sitemap-index', 'sitemap_index');

    expect($sitemap->kind)->toBe('sitemap')
        ->and(array_column($sitemap->entries, 'url'))->toBe([
            'https://www.example.org/news/simple-article',
            'https://www.example.org/news/older-article',
            'https://www.example.org/news/',
        ])
        ->and($sitemap->entries[0]['updated'])->toBe('2026-09-20T00:00:00Z')
        ->and($sitemap->entries[2]['updated'])->toBeNull()
        ->and($index->kind)->toBe('sitemap_index')
        ->and($index->children)->toBe([
            ['url' => 'https://www.example.org/sitemap-news.xml', 'updated' => '2026-09-21T00:00:00Z'],
            ['url' => 'https://www.example.org/sitemap-programs.xml', 'updated' => null],
        ]);
});

// AT-10: external entities are refused, never resolved.
it('refuses XML that declares entities without resolving them', function () {
    expect(fn () => parseXmlFixture('synthetic/xxe-payload'))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::XmlExternalEntityRejected)
            ->and($error->isRetryable())->toBeFalse());
});

// AT-10: malformed XML is a parse failure, not an empty successful feed.
it('reports malformed XML as PARSE_FAILED', function () {
    expect(fn () => parseXmlFixture('synthetic/malformed-xml'))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::ParseFailed)
            ->and($error->getMessage())->toContain('not well-formed'));

    expect(fn () => $this->tool->parse('', 'https://www.example.org/feed.xml'))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::ParseFailed));
});

it('returns a well-formed empty feed as zero entries with a warning', function () {
    $parsed = parseXmlFixture('synthetic/rss-empty');

    expect($parsed->entries)->toBeEmpty()
        ->and($parsed->quality['entry_count'])->toBe(0)
        ->and($parsed->warnings)->toContain('rss document contains no entries.');
});

it('warns when the document kind differs from what the profile expected', function () {
    $parsed = parseXmlFixture('synthetic/rss-basic', 'atom');

    expect($parsed->quality['kind_matches_expected'])->toBeFalse()
        ->and($parsed->warnings)->toContain('Expected atom but document is rss.');
});

it('handles unknown XML vocabularies as a generic document with a warning', function () {
    $parsed = $this->tool->parse('<?xml version="1.0"?><catalog><book id="1"/></catalog>', 'https://www.example.org/x.xml');

    expect($parsed->kind)->toBe('xml')
        ->and($parsed->entries)->toBeEmpty()
        ->and($parsed->warnings[0])->toContain('Unrecognised XML document type: <catalog>');
});

it('is deterministic and round-trips through its wire form', function () {
    $first = parseXmlFixture('synthetic/atom-basic');
    $second = parseXmlFixture('synthetic/atom-basic');

    expect($second->toArray())->toBe($first->toArray())
        ->and(ParsedXml::fromArray($first->toArray())->toArray())->toBe($first->toArray());
});
