<?php

namespace App\Actions;

use Dom\Element;
use Dom\HTMLDocument;
use League\HTMLToMarkdown\HtmlConverter;
use RuntimeException;
use Smalot\PdfParser\Parser;

/**
 * Turn a fetched document into Markdown (stage 2.2 of docs/HANDOVER.md).
 *
 * Deterministic. An HTML page is read with the source's document settings
 * (UI: "Document settings"): the content selector picks the element that
 * holds the body, the remove selectors drop what does not belong in it
 * (share buttons, related links). A PDF is read as its text.
 */
class ReadDocument
{
    /**
     * Keys of the document settings: the CSS selector of the element holding
     * the body of a page, and CSS selectors (comma separated) of elements
     * inside it to drop.
     */
    public const DOCUMENT_CONFIG_KEYS = ['content', 'remove'];

    /** Markdown shorter than this cannot be the body of a document: the settings missed. */
    public const MINIMUM_CHARS = 100;

    /** Never part of a body, whatever the site. */
    private const ALWAYS_REMOVED = 'script, style, noscript, iframe, svg, form, button, nav, template';

    /**
     * The body of an HTML page as Markdown, per the document settings.
     * Throws when the settings are missing, match nothing, or match too
     * little, so the caller can have new settings proposed.
     *
     * @param  array<string, mixed>  $config
     */
    public function html(string $html, array $config, string $url): string
    {
        $selector = trim((string) ($config['content'] ?? ''));

        if ($selector === '') {
            throw new RuntimeException(__('This source has no document settings yet.'));
        }

        // Let the parser sniff the charset from the page unless the bytes are already UTF-8.
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, mb_check_encoding($html, 'UTF-8') ? 'UTF-8' : null);
        $content = $document->querySelector($selector);

        if (! $content instanceof Element) {
            throw new RuntimeException(__('The document settings matched nothing on this page.'));
        }

        // Drop what is never body text, then what the settings say to drop.
        foreach (array_filter([self::ALWAYS_REMOVED, trim((string) ($config['remove'] ?? ''))]) as $dropped) {
            foreach ($content->querySelectorAll($dropped) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Links and images must still work once the Markdown is read away from the page.
        foreach ([['a', 'href'], ['img', 'src']] as [$tag, $attribute]) {
            foreach ($content->querySelectorAll("{$tag}[{$attribute}]") as $node) {
                $node->setAttribute($attribute, FetchUpdates::absolute(trim((string) $node->getAttribute($attribute)), $url));
            }
        }

        $markdown = (new HtmlConverter(['header_style' => 'atx', 'strip_tags' => true, 'strip_placeholder_links' => true]))
            ->convert($content->innerHTML);
        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));

        if (mb_strlen($markdown) < self::MINIMUM_CHARS) {
            throw new RuntimeException(__('The document settings matched too little on this page (:count characters).', ['count' => mb_strlen($markdown)]));
        }

        return $markdown;
    }

    /**
     * The text of a PDF, page after page. Layout is not reconstructed:
     * lines stay as printed, blank lines separate blocks.
     */
    public function pdf(string $bytes): string
    {
        $text = (new Parser)->parseContent($bytes)->getText();
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        if ($text === '') {
            throw new RuntimeException(__('No text could be read from this PDF.'));
        }

        return $text;
    }
}
