<?php

namespace App\Acquisition\Tools\Parsers;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Models\SourceProfile;
use App\Acquisition\Tools\Html\ParsedHtml;
use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Pdf\ParsedPdf;
use App\Acquisition\Tools\Pdf\ParsePdfTool;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\Xml\ParsedXml;
use App\Acquisition\Tools\Xml\ParseXmlTool;

/**
 * Turns a media type into a parser ID (profile bindings first, then the
 * built-in defaults) and a parser ID into a parse. This is the single place
 * that knows which class implements which versioned ID (plan §11).
 */
final class ParserResolver
{
    /**
     * @var array<string, string>
     */
    private const DEFAULT_BINDINGS = [
        'text/html' => ParserRegistry::HTML_GENERIC_V1,
        'application/xhtml+xml' => ParserRegistry::HTML_GENERIC_V1,
        'text/xml' => ParserRegistry::XML_FEED_V1,
        'application/xml' => ParserRegistry::XML_FEED_V1,
        'application/rss+xml' => ParserRegistry::XML_FEED_V1,
        'application/atom+xml' => ParserRegistry::XML_FEED_V1,
        'application/pdf' => ParserRegistry::PDF_TEXT_V1,
    ];

    public function __construct(
        private ParseHtmlTool $html,
        private ParseXmlTool $xml,
        private ParsePdfTool $pdf,
    ) {}

    /**
     * The parser bound to a media type, from the profile when it says so.
     *
     * @throws ToolError with ErrorCode::InvalidInput when nothing can parse the type
     */
    public function parserIdFor(string $mediaType, ?SourceProfile $profile = null): string
    {
        $mediaType = strtolower(trim(explode(';', $mediaType, 2)[0]));
        /** @var array<string, string> $bindings */
        $bindings = $profile?->profile_json['parser_bindings'] ?? [];
        $parserId = $bindings[$mediaType] ?? self::DEFAULT_BINDINGS[$mediaType] ?? null;

        if ($parserId === null) {
            throw new ToolError(ErrorCode::InvalidInput, "No parser is bound to media type {$mediaType}.", ['media_type' => $mediaType]);
        }

        return $parserId;
    }

    /**
     * Whether a parser can read bytes of the given media type at all.
     */
    public function appliesTo(string $parserId, string $mediaType): bool
    {
        $mediaType = strtolower(trim(explode(';', $mediaType, 2)[0]));
        $kind = explode('.', $parserId, 2)[0];
        $family = explode('.', self::DEFAULT_BINDINGS[$mediaType] ?? '', 2)[0];

        return $family !== '' && $family === $kind;
    }

    /**
     * Run the parser behind an ID.
     *
     * @throws ToolError with ErrorCode::InvalidInput for IDs this build does not ship
     */
    public function parse(string $parserId, string $bytes, string $url): ParsedHtml|ParsedXml|ParsedPdf
    {
        return match ($parserId) {
            ParserRegistry::HTML_GENERIC_V1 => $this->html->parse($bytes, $url),
            ParserRegistry::XML_FEED_V1 => $this->xml->parse($bytes, $url),
            ParserRegistry::PDF_TEXT_V1 => $this->pdf->parse($bytes, $url),
            default => throw new ToolError(ErrorCode::InvalidInput, "Unknown parser {$parserId}.", ['parser_id' => $parserId]),
        };
    }
}
