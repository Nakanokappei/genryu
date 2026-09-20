<?php

namespace App\Acquisition\Tools\Pdf;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * PDF Tool, parser pdf.text@1 (plan §7.5). Extracts the text layer page by
 * page with a pure-PHP parser and joins it into Markdown with page markers.
 * Scanned, encrypted and corrupt files each fail with their own code
 * (AT-11); none of them is ever reported as an empty success.
 */
final class ParsePdfTool implements Tool
{
    public function name(): string
    {
        return 'parse_pdf';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        return ParsePdfRequest::fromArray($payload);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof ParsePdfRequest) {
            throw new ToolError(ErrorCode::InvalidInput, 'parse_pdf expects a ParsePdfRequest.');
        }

        return $this->parse($request->pdf, $request->url);
    }

    /**
     * Parse PDF bytes served from $url. Usable directly by PHP callers.
     */
    public function parse(string $pdf, string $url): ParsedPdf
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new ToolError(ErrorCode::ParseFailed, 'Bytes do not start with a PDF header.', ['url' => $url]);
        }

        // The library refuses encrypted files with an exception; detect the
        // trailer entry first so the failure gets its own code.
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $pdf) === 1) {
            throw new ToolError(ErrorCode::EncryptedPdf, 'PDF is encrypted; text extraction is not supported.', ['url' => $url]);
        }

        try {
            $document = (new Parser)->parseContent($pdf);
            $pages = $document->getPages();
            $details = $document->getDetails();
        } catch (Throwable $exception) {
            throw new ToolError(ErrorCode::ParseFailed, 'PDF could not be parsed.', ['url' => $url], null, $exception);
        }

        $extracted = [];
        $warnings = [];

        foreach ($pages as $index => $page) {
            $number = $index + 1;

            try {
                $text = $this->normalizeText($page->getText());
            } catch (Throwable) {
                $text = '';
                $warnings[] = "Page {$number}: text extraction failed.";
            }

            if ($text === '') {
                $warnings[] = "Page {$number} has no text layer.";
            }

            $extracted[] = ['number' => $number, 'text' => $text];
        }

        $withText = array_filter($extracted, static fn (array $page): bool => $page['text'] !== '');

        // AT-11: a file with pages but no text anywhere is a scan (or an
        // image-only export). Phase 0 has no OCR, so this is an explicit
        // unsupported state rather than a document with an empty body.
        if ($extracted !== [] && $withText === []) {
            throw new ToolError(ErrorCode::UnsupportedScannedPdf, 'PDF has no text layer on any page.', ['url' => $url, 'page_count' => count($extracted)]);
        }

        if ($extracted === []) {
            throw new ToolError(ErrorCode::ParseFailed, 'PDF contains no pages.', ['url' => $url]);
        }

        $markdown = implode("\n\n", array_map(
            static fn (array $page): string => "<!-- page {$page['number']} -->\n\n{$page['text']}",
            array_values($withText),
        ));

        $metadata = $this->metadata($details);
        $textCharacters = array_sum(array_map(static fn (array $page): int => mb_strlen($page['text']), $extracted));

        return new ParsedPdf(
            metadata: $metadata,
            pageCount: count($extracted),
            pages: $extracted,
            markdown: $markdown,
            extractionMethod: 'text_layer',
            encrypted: false,
            quality: [
                'text_characters' => $textCharacters,
                'page_count' => count($extracted),
                'pages_without_text' => count($extracted) - count($withText),
                'has_title' => isset($metadata['title']),
            ],
            warnings: $warnings,
        );
    }

    /**
     * Info dictionary as lower-cased string values; dates are left as the
     * library formats them and interpreted downstream.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    private function metadata(array $details): array
    {
        $metadata = [];

        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $metadata[strtolower((string) $key)] = $value;
            }
        }

        ksort($metadata);

        return $metadata;
    }

    /**
     * Collapse the parser's spacing artefacts while keeping line breaks, so
     * equal PDFs yield equal text.
     */
    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
