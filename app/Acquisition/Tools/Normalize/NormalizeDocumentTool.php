<?php

namespace App\Acquisition\Tools\Normalize;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Identity\StableKeyResolver;
use App\Acquisition\Tools\Html\ParsedHtml;
use App\Acquisition\Tools\Parsers\ParserRegistry;
use App\Acquisition\Tools\Pdf\ParsedPdf;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;
use App\Acquisition\Tools\Xml\ParsedXml;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Normalize Tool, normalize.document@1 (plan §7.6). Folds HTML, XML and PDF
 * parser output into the common Document Model, derives the stable
 * identity (ADR-0003), picks dates from candidates without discarding
 * them, and evaluates the profile's quality expectations. It records the
 * verdict; it does not throw on poor quality, because the run engine
 * decides what a failed document means for the run (plan §3.6).
 */
final class NormalizeDocumentTool implements Tool
{
    public function name(): string
    {
        return 'normalize_document';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        return NormalizeRequest::fromArray($payload);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof NormalizeRequest) {
            throw new ToolError(ErrorCode::InvalidInput, 'normalize_document expects a NormalizeRequest.');
        }

        return $this->normalize($request->parsed, $request->context);
    }

    /**
     * Usable directly by PHP callers.
     */
    public function normalize(ParsedHtml|ParsedXml|ParsedPdf $parsed, SourceContext $context): NormalizedDocument
    {
        return match (true) {
            $parsed instanceof ParsedHtml => $this->fromHtml($parsed, $context),
            $parsed instanceof ParsedXml => $this->fromXml($parsed, $context),
            default => $this->fromPdf($parsed, $context),
        };
    }

    /**
     * A PDF's canonical URL is the URL it was served from; its title comes
     * from the Info dictionary when present.
     */
    private function fromPdf(ParsedPdf $parsed, SourceContext $context): NormalizedDocument
    {
        $identity = StableKeyResolver::resolve($context->feedGuid, null, $context->finalUrl, $context->stripQueryParameters);
        $warnings = $parsed->warnings;
        $published = $this->pdfDate($parsed->metadata['creationdate'] ?? null, $warnings);
        $updated = $this->pdfDate($parsed->metadata['moddate'] ?? null, $warnings);

        // Many official PDFs carry no Info title; the first line of page one
        // is the next best deterministic witness and is marked as such.
        $title = $parsed->metadata['title'] ?? null;
        $titleSource = 'pdf_metadata';

        if ($title === null) {
            $firstLine = trim((string) strtok($parsed->pages[0]['text'] ?? '', "\n"));

            if ($firstLine !== '') {
                $title = mb_substr($firstLine, 0, 200);
                $titleSource = 'first_line';
                $warnings[] = 'PDF has no title metadata; used the first line of page 1.';
            }
        }

        return $this->build(
            context: $context,
            stableKey: $identity->key,
            identityRule: $identity->rule,
            canonicalUrl: $context->finalUrl,
            title: $title,
            documentType: $context->documentType,
            publishedAt: $published,
            updatedAt: $updated,
            language: null,
            parserId: $parsed->parserId,
            outboundLinks: [],
            body: $parsed->markdown,
            textCharacters: (int) ($parsed->quality['text_characters'] ?? mb_strlen($parsed->markdown)),
            warnings: $warnings,
            provenance: [
                'pdf_metadata' => $parsed->metadata,
                'title_source' => $titleSource,
                'page_count' => $parsed->pageCount,
                'extraction_method' => $parsed->extractionMethod,
                'parser_quality' => $parsed->quality,
            ],
        );
    }

    /**
     * PDF Info dates arrive either as the library's formatted string or in
     * the raw D:YYYYMMDDHHmmSS form; both are normalized to ISO 8601 UTC.
     *
     * @param  list<string>  $warnings
     */
    private function pdfDate(?string $raw, array &$warnings): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = preg_replace('/^D:(\d{4})(\d{2})(\d{2})(\d{2})?(\d{2})?(\d{2})?.*$/', '$1-$2-$3T$4:$5:$6', $raw) ?? $raw;
        $value = rtrim(str_replace('T::', 'T00:00:00', $value), ':');

        try {
            return CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
        } catch (InvalidFormatException) {
            $warnings[] = "Unparseable PDF date: {$raw}";

            return null;
        }
    }

    private function fromHtml(ParsedHtml $parsed, SourceContext $context): NormalizedDocument
    {
        $identity = StableKeyResolver::resolve($context->feedGuid, $parsed->canonicalUrl, $context->finalUrl, $context->stripQueryParameters);
        $candidates = $parsed->dateCandidates;

        // The feed that listed this document is an authoritative, if external,
        // witness of its publication date: stronger than text heuristics,
        // weaker than the page's own metadata.
        if ($context->feedPublishedAt !== null) {
            $candidates[] = ['kind' => 'published', 'value' => $context->feedPublishedAt, 'raw' => $context->feedPublishedAt, 'source' => 'feed:published', 'confidence' => 0.8];
            usort($candidates, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
        }

        $published = $this->pick($candidates, 'published');
        $updated = $this->pick($candidates, 'updated');
        $links = array_values(array_unique(array_map(static fn (array $link): string => $link['url'], $parsed->links)));

        return $this->build(
            context: $context,
            stableKey: $identity->key,
            identityRule: $identity->rule,
            canonicalUrl: $parsed->canonicalUrl,
            title: $parsed->title,
            documentType: $context->documentType,
            publishedAt: $published,
            updatedAt: $updated,
            language: $parsed->language,
            parserId: $parsed->parserId,
            outboundLinks: $links,
            body: $parsed->markdown,
            textCharacters: (int) ($parsed->quality['text_characters'] ?? mb_strlen($parsed->text)),
            warnings: $parsed->warnings,
            provenance: [
                'date_candidates' => $candidates,
                'author' => $parsed->author,
                'main_content_selector' => $parsed->mainContentSelector,
                'headings' => $parsed->headings,
                'parser_quality' => $parsed->quality,
            ],
        );
    }

    /**
     * A feed or sitemap is a document too: its body lists the entries so the
     * RAW -> NORMALIZED contract holds for every media type (AT-04).
     */
    private function fromXml(ParsedXml $parsed, SourceContext $context): NormalizedDocument
    {
        $identity = StableKeyResolver::resolve($context->feedGuid, null, $context->finalUrl, $context->stripQueryParameters);
        $lines = [];
        $links = [];

        foreach ($parsed->entries as $entry) {
            $label = $entry['title'] ?? $entry['url'] ?? $entry['id'] ?? '(untitled)';
            $when = $entry['published'] ?? $entry['updated'];
            $lines[] = '- '.($entry['url'] !== null ? "[{$label}]({$entry['url']})" : $label).($when !== null ? " ({$when})" : '');

            if ($entry['url'] !== null) {
                $links[] = $entry['url'];
            }
        }

        foreach ($parsed->children as $child) {
            $lines[] = "- [{$child['url']}]({$child['url']})".($child['updated'] !== null ? " ({$child['updated']})" : '');
            $links[] = $child['url'];
        }

        $body = $lines === [] ? '(no entries)' : implode("\n", $lines);

        return $this->build(
            context: $context,
            stableKey: $identity->key,
            identityRule: $identity->rule,
            canonicalUrl: $parsed->feed['url'] ?? $context->finalUrl,
            title: $parsed->feed['title'] ?? $context->finalUrl,
            documentType: $context->documentType ?? $parsed->kind,
            publishedAt: null,
            updatedAt: $parsed->feed['updated'],
            language: null,
            parserId: $parsed->parserId,
            outboundLinks: array_values(array_unique($links)),
            body: $body,
            textCharacters: mb_strlen($body),
            warnings: $parsed->warnings,
            provenance: ['kind' => $parsed->kind, 'pagination' => $parsed->pagination, 'parser_quality' => $parsed->quality],
        );
    }

    /**
     * Assemble the document and evaluate quality expectations.
     *
     * @param  list<string>  $outboundLinks
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $provenance
     */
    private function build(
        SourceContext $context,
        string $stableKey,
        string $identityRule,
        ?string $canonicalUrl,
        ?string $title,
        ?string $documentType,
        ?string $publishedAt,
        ?string $updatedAt,
        ?string $language,
        string $parserId,
        array $outboundLinks,
        string $body,
        int $textCharacters,
        array $warnings,
        array $provenance,
    ): NormalizedDocument {
        $fields = [
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'published_at' => $publishedAt,
            'updated_at' => $updatedAt,
            'language' => $language,
            'body' => $body !== '' ? $body : null,
        ];

        $missing = array_values(array_filter(
            $context->requiredFields,
            static fn (string $field): bool => ! isset($fields[$field]) || trim($fields[$field]) === '',
        ));

        $quality = [
            'text_characters' => $textCharacters,
            'required_fields_missing' => $missing,
            'passed' => $missing === [] && $textCharacters >= $context->minimumTextCharacters,
        ];

        if ($textCharacters < $context->minimumTextCharacters) {
            $warnings[] = "Text has {$textCharacters} characters, below the expected minimum of {$context->minimumTextCharacters}.";
        }

        return new NormalizedDocument(
            source: $context->sourceKey,
            stableKey: $stableKey,
            identityRule: $identityRule,
            canonicalUrl: $canonicalUrl,
            sourceUrl: $context->finalUrl,
            requestedUrl: $context->requestedUrl,
            title: $title,
            documentType: $documentType,
            publishedAt: $publishedAt,
            updatedAt: $updatedAt,
            retrievedAt: $context->retrievedAt->toIso8601ZuluString(),
            language: $language,
            contentType: $context->mediaType,
            parserId: $parserId,
            normalizerVersion: ParserRegistry::NORMALIZE_DOCUMENT_V1,
            rawSha256: $context->rawSha256,
            rawBlobUri: $context->rawBlobUri,
            outboundLinks: $outboundLinks,
            body: $body,
            quality: $quality,
            warnings: $warnings,
            provenance: $provenance,
        );
    }

    /**
     * Highest-confidence parsed candidate of a kind, or null. Candidates
     * arrive sorted by confidence; ties keep parser order.
     *
     * @param  list<array{kind: string, value: string|null, raw: string, source: string, confidence: float}>  $candidates
     */
    private function pick(array $candidates, string $kind): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate['kind'] === $kind && $candidate['value'] !== null) {
                return $candidate['value'];
            }
        }

        return null;
    }
}
