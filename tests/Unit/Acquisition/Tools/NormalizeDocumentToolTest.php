<?php

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Normalize\SourceContext;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\Xml\ParseXmlTool;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->normalizer = new NormalizeDocumentTool;
});

/**
 * Source context for a fixture, with optional overrides.
 *
 * @param  array<string, mixed>  $overrides
 */
function contextFor(string $fixtureName, array $overrides = []): SourceContext
{
    $fixture = acquisitionFixture($fixtureName);

    return SourceContext::fromArray(array_replace_recursive([
        'source_key' => 'example',
        'requested_url' => $fixture['meta']['url'],
        'final_url' => $fixture['meta']['final_url'],
        'retrieved_at' => $fixture['meta']['retrieved_at'],
        'media_type' => $fixture['meta']['media_type'],
        'raw_sha256' => hash('sha256', $fixture['body']),
        'raw_blob_uri' => 'acquisition://raw/ab/cd/'.hash('sha256', $fixture['body']),
        'document_type' => 'news',
    ], $overrides));
}

it('normalizes a parsed article into the common document model with provenance', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');
    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);

    $document = $this->normalizer->normalize($parsed, contextFor('synthetic/simple-article'));

    expect($document->source)->toBe('example')
        ->and($document->stableKey)->toBe('url:https://www.example.org/news/simple-article')
        ->and($document->identityRule)->toBe('canonical')
        ->and($document->requestedUrl)->toBe('https://www.example.org/news/simple-article?utm_source=test')
        ->and($document->title)->toBe('Example Agency Announces New Research Program')
        ->and($document->publishedAt)->toBe('2026-09-20T09:00:00Z')
        ->and($document->updatedAt)->toBeNull()
        ->and($document->retrievedAt)->toBe('2026-09-21T00:00:00Z')
        ->and($document->parserId)->toBe('html.generic@1')
        ->and($document->normalizerVersion)->toBe('normalize.document@1')
        ->and($document->rawSha256)->toBe(hash('sha256', $fixture['body']))
        ->and($document->outboundLinks)->toHaveCount(2)
        ->and($document->quality)->toMatchArray(['required_fields_missing' => [], 'passed' => true])
        ->and($document->provenance['date_candidates'])->toBe($parsed->dateCandidates);
});

it('prefers a feed GUID over the canonical URL for identity when the context supplies one', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');
    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);

    $document = $this->normalizer->normalize($parsed, contextFor('synthetic/simple-article', ['feed_guid' => 'tag:example.org,2026:news/1001']));

    expect($document->stableKey)->toBe('guid:tag:example.org,2026:news/1001')
        ->and($document->identityRule)->toBe('feed_guid');
});

// AT-04: the Markdown carries stable front matter in a fixed field order and hashes identically across runs.
it('emits front matter in contract order and hashes identically for identical input', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');
    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);

    $first = $this->normalizer->normalize($parsed, contextFor('synthetic/simple-article'));
    $second = $this->normalizer->normalize($parsed, contextFor('synthetic/simple-article'));
    $markdown = $first->toMarkdown();

    expect($first->sha256())->toBe($second->sha256())
        ->and($markdown)->toStartWith("---\nschema_version: 1\nsource: \"example\"\nstable_key: ")
        ->and(array_keys($first->frontMatter()))->toBe([
            'schema_version', 'source', 'stable_key', 'identity_rule', 'canonical_url', 'source_url', 'requested_url',
            'title', 'document_type', 'published_at', 'updated_at', 'retrieved_at', 'language', 'content_type',
            'parser_id', 'normalizer_version', 'raw_sha256', 'raw_blob_uri', 'outbound_links', 'quality', 'warnings',
        ])
        ->and($markdown)->toContain("---\n\n# Example Agency Announces New Research Program")
        ->and(substr_count($markdown, "\n---\n"))->toBe(1);
});

// AT-08: a shell page fails quality with explicit reasons.
it('marks an empty shell page as failing quality with the missing fields named', function () {
    $fixture = acquisitionFixture('synthetic/empty-page');
    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);

    $document = $this->normalizer->normalize($parsed, contextFor('synthetic/empty-page'));

    expect($document->quality['passed'])->toBeFalse()
        ->and($document->quality['required_fields_missing'])->toBe(['canonical_url', 'title'])
        ->and($document->quality['text_characters'])->toBe(0)
        ->and($document->warnings)->toContain('Text has 0 characters, below the expected minimum of 200.')
        ->and($document->stableKey)->toBe('url:https://www.example.org/news/simple-article')
        ->and($document->identityRule)->toBe('url');
});

it('normalizes a feed into a document that lists its entries', function () {
    $fixture = acquisitionFixture('synthetic/rss-basic');
    $parsed = (new ParseXmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);

    $document = $this->normalizer->normalize($parsed, contextFor('synthetic/rss-basic', [
        'document_type' => null,
        'quality_expectations' => ['required_fields' => ['title'], 'minimum_text_characters' => 10],
    ]));

    expect($document->documentType)->toBe('rss')
        ->and($document->title)->toBe('Example Agency News')
        ->and($document->updatedAt)->toBe('2026-09-21T08:00:00Z')
        ->and($document->body)->toContain('- [New Research Program Announced](https://www.example.org/news/simple-article?utm_source=rss) (2026-09-20T09:00:00Z)')
        ->and($document->outboundLinks)->toHaveCount(2)
        ->and($document->quality['passed'])->toBeTrue();
});

it('accepts the wire form of a parsed artifact and rejects unknown parser kinds', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');
    $parsed = (new ParseHtmlTool)->parse($fixture['body'], $fixture['meta']['final_url']);
    $context = new ToolContext(1, 'c');

    $viaWire = $this->normalizer->run($this->normalizer->parseRequest([
        'parsed' => $parsed->toArray(),
        'source_context' => contextFor('synthetic/simple-article')->toArray(),
    ]), $context);
    $direct = $this->normalizer->normalize($parsed, contextFor('synthetic/simple-article'));

    expect($viaWire->toArray())->toBe($direct->toArray());

    expect(fn () => $this->normalizer->parseRequest([
        'parsed' => ['parser_id' => 'docx.text@1'],
        'source_context' => contextFor('synthetic/simple-article')->toArray(),
    ]))->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::InvalidInput));
});

it('rejects a source context with an invalid hash or key', function () {
    expect(fn () => SourceContext::fromArray([
        'source_key' => 'Bad Key', 'requested_url' => 'https://a.example/', 'final_url' => 'https://a.example/',
        'retrieved_at' => CarbonImmutable::now()->toIso8601String(), 'media_type' => 'text/html', 'raw_sha256' => 'short',
    ]))->toThrow(fn (ToolError $error) => expect($error->details['violations'])->toHaveKeys(['source_key', 'raw_sha256']));
});
