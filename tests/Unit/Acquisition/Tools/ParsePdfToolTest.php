<?php

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Normalize\SourceContext;
use App\Acquisition\Tools\Pdf\ParsedPdf;
use App\Acquisition\Tools\Pdf\ParsePdfTool;
use App\Acquisition\Tools\ToolError;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->tool = new ParsePdfTool;
});

/**
 * Parse a PDF fixture with the tool under test.
 */
function parsePdfFixture(string $name): ParsedPdf
{
    $fixture = acquisitionFixture($name);

    return test()->tool->parse($fixture['body'], $fixture['meta']['final_url']);
}

it('extracts page text, metadata and Markdown with page markers from a text PDF', function () {
    $parsed = parsePdfFixture('synthetic/pdf-text');

    expect($parsed->parserId)->toBe('pdf.text@1')
        ->and($parsed->pageCount)->toBe(2)
        ->and($parsed->metadata['title'])->toBe('Fixture Parsing BAA')
        ->and($parsed->metadata['author'])->toBe('Example Agency')
        ->and($parsed->pages[0]['text'])->toContain('Broad Agency Announcement: Fixture Parsing Program')
        ->and($parsed->pages[1]['text'])->toContain('Proposals are due November 1, 2026.')
        ->and($parsed->markdown)->toContain('<!-- page 1 -->')
        ->and($parsed->markdown)->toContain('<!-- page 2 -->')
        ->and($parsed->extractionMethod)->toBe('text_layer')
        ->and($parsed->encrypted)->toBeFalse()
        ->and($parsed->quality)->toMatchArray(['page_count' => 2, 'pages_without_text' => 0, 'has_title' => true])
        ->and($parsed->quality['text_characters'])->toBeGreaterThan(200)
        ->and($parsed->warnings)->toBeEmpty();
});

// AT-11: scan-only, encrypted and corrupt PDFs are explicit non-success states.
// A NEDO PDF's Title decoded to UTF-8-encoded surrogates: mb_check_encoding() accepts them,
// json_encode() and every jsonb column reject them, and one document failed a whole run.
it('replaces byte salad in PDF strings so every result is JSON-safe', function () {
    // The exact kind of bytes the NEDO title carried: a UTF-8-encoded surrogate (U+DE30) and a truncated sequence.
    $surrogate = "実施方針｜\xED\xB8\xB0R";
    $truncated = "Title \xE6\x97";

    expect(json_encode($surrogate))->toBeFalse()
        ->and(ParsePdfTool::jsonSafeUtf8($surrogate))->toStartWith('実施方針｜')->toEndWith('R')->toContain("\u{FFFD}")
        ->and(ParsePdfTool::jsonSafeUtf8($truncated))->toStartWith('Title ')->toContain("\u{FFFD}")
        ->and(ParsePdfTool::jsonSafeUtf8('plain ascii'))->toBe('plain ascii')
        ->and(json_encode(ParsePdfTool::jsonSafeUtf8($surrogate)))->not->toBeFalse()
        ->and(json_encode(ParsePdfTool::jsonSafeUtf8($truncated)))->not->toBeFalse();
});

it('reports a PDF without any text layer as UNSUPPORTED_SCANNED_PDF', function () {
    expect(fn () => parsePdfFixture('synthetic/pdf-image-only'))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::UnsupportedScannedPdf)
            ->and($error->details['page_count'])->toBe(1)
            ->and($error->isRetryable())->toBeFalse());
});

it('reports an encrypted PDF as ENCRYPTED_PDF', function () {
    expect(fn () => parsePdfFixture('synthetic/pdf-encrypted'))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::EncryptedPdf));
});

it('reports corrupt bytes and non-PDF bytes as PARSE_FAILED', function () {
    expect(fn () => parsePdfFixture('synthetic/pdf-corrupt'))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::ParseFailed));

    expect(fn () => $this->tool->parse('<html>not a pdf</html>', 'https://www.example.org/x.pdf'))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::ParseFailed));
});

it('is deterministic and round-trips through its wire form', function () {
    $first = parsePdfFixture('synthetic/pdf-text');
    $second = parsePdfFixture('synthetic/pdf-text');

    expect($second->toArray())->toBe($first->toArray())
        ->and(ParsedPdf::fromArray($first->toArray())->toArray())->toBe($first->toArray());
});

// AT-04 for PDF: the common document model with the served URL as canonical.
it('normalizes into the common document model with the PDF metadata as provenance', function () {
    $fixture = acquisitionFixture('synthetic/pdf-text');
    $parsed = parsePdfFixture('synthetic/pdf-text');
    $context = new SourceContext('example', $fixture['meta']['url'], $fixture['meta']['final_url'], CarbonImmutable::parse('2026-09-21T00:00:00Z'), 'application/pdf', hash('sha256', $fixture['body']), null, null, 'report');

    $document = (new NormalizeDocumentTool)->normalize($parsed, $context);

    expect($document->title)->toBe('Fixture Parsing BAA')
        ->and($document->canonicalUrl)->toBe('https://www.example.org/files/pdf-text.pdf')
        ->and($document->stableKey)->toBe('url:https://www.example.org/files/pdf-text.pdf')
        ->and($document->documentType)->toBe('report')
        ->and($document->publishedAt)->toBe('2026-09-18T15:30:00Z')
        ->and($document->parserId)->toBe('pdf.text@1')
        ->and($document->body)->toContain('Technical Area 2: Provenance')
        ->and($document->quality['passed'])->toBeTrue()
        ->and($document->provenance['page_count'])->toBe(2);
});
