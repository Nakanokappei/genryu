<?php

namespace App\Acquisition\Tools\Parsers;

/**
 * The parser and normalizer versions this build knows how to run (plan §11,
 * ADR-0005). A Source Profile may only bind media types to IDs listed here;
 * reprocessing resolves IDs through the same list.
 */
final class ParserRegistry
{
    public const HTML_GENERIC_V1 = 'html.generic@1';

    public const XML_FEED_V1 = 'xml.feed@1';

    public const PDF_TEXT_V1 = 'pdf.text@1';

    public const NORMALIZE_DOCUMENT_V1 = 'normalize.document@1';

    /**
     * Pattern every parser ID must match: <kind>.<name>@<version>.
     */
    public const ID_PATTERN = '/^(html|xml|pdf|normalize)\.[a-z0-9_]+@[0-9]+$/';

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return [self::HTML_GENERIC_V1, self::XML_FEED_V1, self::PDF_TEXT_V1, self::NORMALIZE_DOCUMENT_V1];
    }

    public static function has(string $parserId): bool
    {
        return in_array($parserId, self::ids(), true);
    }
}
