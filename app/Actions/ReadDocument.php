<?php

namespace App\Actions;

use App\Pdf\PdfMarkdown;
use App\Pdf\PdfParser;
use Dom\Element;
use Dom\HTMLDocument;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use RuntimeException;

/**
 * Turn a fetched document into Markdown (stage 2.2 of docs/HANDOVER.md).
 *
 * Deterministic. An HTML page is read with the source's document settings
 * (UI: "Document settings"): the content selector picks the element that
 * holds the body, the date selector the element holding its date, the
 * remove selectors drop what does not belong in it (share buttons, related
 * links, navigation), the fixed text selectors pick the notices and
 * copyright lines that are kept but moved after the body. The Markdown
 * reads heading, date, body, fixed text; headings keep their relative
 * levels with "##" as the top level. A PDF is read as its text, a secured
 * one (press releases are often saved with an empty user password) after
 * decryption by App\Pdf\PdfParser.
 */
class ReadDocument
{
    /**
     * Keys of the document settings: CSS selectors of the element holding
     * the body of a page, of the element holding its date, of elements
     * inside the body to drop, and of fixed text to move after the body
     * (the last two comma separated).
     */
    public const DOCUMENT_CONFIG_KEYS = ['content', 'date', 'remove', 'fixed_text'];

    /** Markdown shorter than this cannot be the body of a document: the settings missed. */
    public const MINIMUM_CHARS = 100;

    /** The level the document title gets ("#", which Markdown has no other way to mark); body headings start one below it. */
    private const TOP_LEVEL = 1;

    /** Where the title of a document may be inside the body (三菱電機: <h2 class="article-header__title">). */
    private const TITLE_CANDIDATES = 'h1, h2, h3, h4';

    /** A link that only leads back to a list or a top page, whatever the site. */
    private const NAVIGATION_LINK_PATTERN = '/(へ戻る|に戻る|^戻る$|一覧へ$|^back to\b|^return to\b|^go back\b|^zurück\b|^retour\b)/iu';

    private const NAVIGATION_LINK_MAX_CHARS = 40;

    /** Never part of a body, whatever the site: scripts, controls and navigation. */
    private const ALWAYS_REMOVED = 'script, style, noscript, iframe, svg, form, button, nav, template, [role="navigation"], .breadcrumb, .breadcrumbs, .pager, .pagination';

    /** Where the date of a document usually is when the settings do not say. */
    private const DATE_FALLBACK = 'time[datetime], time';

    /** Elements short enough to be a date line on their own (DARPA: <h5 class="news-date">June 26, 2026</h5>). */
    private const DATE_LINE_CANDIDATES = 'h2, h3, h4, h5, h6, p, span, div, li';

    private const DATE_LINE_MAX_CHARS = 40;

    /** What a printed date looks like: 2026年9月10日, 2026-09-10, 10.09.2026, June 26, 2026, 26 June 2026. */
    private const DATE_TEXT_PATTERN = '/\d{4}[年.\/-]\d{1,2}[月.\/-]\d{1,2}|\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}\b|\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{1,2},? \d{4}\b|\b\d{1,2}\.? (jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{4}\b/iu';

    private const DATE_META = 'meta[property="article:published_time"], meta[name="date"], meta[name="pubdate"]';

    /** A paragraph that starts like this is fixed text, whatever the settings say. */
    private const FIXED_TEXT_PATTERN = '/^(©|\(c\)|copyright\b|all rights reserved|無断転載|著作権|※|掲載の(データ|情報|内容)は|発表(当時|時点)の)/iu';

    /**
     * The body of an HTML page as Markdown, per the document settings.
     * Throws when the settings are missing, match nothing, or match too
     * little, so the caller can have new settings proposed.
     *
     * @param  array<string, mixed>  $config
     */
    public function html(string $html, array $config, string $url, ?string $title = null): string
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

        // The date the settings point at is taken first: it may sit in a block the settings then drop (日立: the author line).
        $date = self::takeDate($document, $content, trim((string) ($config['date'] ?? '')), fallbacks: false);

        // Drop what is never body text, then what the settings say to drop.
        foreach (array_filter([self::ALWAYS_REMOVED, trim((string) ($config['remove'] ?? ''))]) as $dropped) {
            self::takeOut($document, $content, $dropped);
        }

        // Links that only lead back to the list are navigation, wherever they sit.
        self::dropNavigationLinks($content);

        // Without a configured date, the usual places are tried once the noise is gone; the fixed text leaves the body for its end.
        $date ??= self::takeDate($document, $content, '', fallbacks: true);
        $fixedHtml = self::takeOut($document, $content, trim((string) ($config['fixed_text'] ?? '')));

        // The title, taken out of the body when it is there.
        $heading = self::takeTitle($document, $content, $title);

        // Links and images must still work once the Markdown is read away from the page.
        foreach ([['a', 'href'], ['img', 'src']] as [$tag, $attribute]) {
            foreach ($content->querySelectorAll("{$tag}[{$attribute}]") as $node) {
                $node->setAttribute($attribute, FetchUpdates::absolute(trim((string) $node->getAttribute($attribute)), $url));
            }
        }

        $converter = new HtmlConverter(['header_style' => 'atx', 'strip_tags' => true, 'strip_placeholder_links' => true]);
        $converter->getEnvironment()->addConverter(new TableConverter);
        $markdown = self::tidy($converter->convert($content->innerHTML));

        if (mb_strlen($markdown) < self::MINIMUM_CHARS) {
            throw new RuntimeException(__('The document settings matched too little on this page (:count characters).', ['count' => mb_strlen($markdown)]));
        }

        // Fixed text found by its wording joins what the settings pointed at, after the body.
        [$markdown, $notices] = self::splitFixedText($markdown);
        $fixed = self::tidy(implode("\n\n", [$fixedHtml !== '' ? $converter->convert($fixedHtml) : '', ...$notices]));

        return implode("\n\n", array_filter([
            $heading !== '' ? str_repeat('#', self::TOP_LEVEL)." {$heading}" : '',
            $date,
            self::shiftHeadings($markdown, self::TOP_LEVEL + 1),
            $fixed !== '' ? "---\n\n{$fixed}" : '',
        ]));
    }

    /**
     * A PDF as Markdown, in the same shape as an HTML page: the title (what
     * the update list said, found on the page and taken out), the date,
     * the body with its headings and tables read from the layout
     * (App\Pdf\PdfMarkdown), then the fixed text after a rule.
     */
    public function pdf(string $bytes, ?string $title = null): string
    {
        ['title' => $heading, 'date' => $date, 'body' => $body] = (new PdfMarkdown)((new PdfParser)->parseContent($bytes), $title);

        if ($body === '') {
            throw new RuntimeException(__('No text could be read from this PDF.'));
        }

        [$body, $notices] = self::splitFixedText($body);
        $fixed = self::tidy(implode("\n\n", $notices));

        return implode("\n\n", array_filter([
            $heading !== '' ? str_repeat('#', self::TOP_LEVEL)." {$heading}" : '',
            $date !== null ? self::dateText($date) : '',
            self::tidy(self::shiftHeadings($body, self::TOP_LEVEL + 1)),
            $fixed !== '' ? "---\n\n{$fixed}" : '',
        ]));
    }

    /**
     * Remove the elements a selector matches inside the body, and hand
     * back their HTML so they can be printed elsewhere.
     */
    private static function takeOut(HTMLDocument $document, Element $content, string $selector): string
    {
        if ($selector === '') {
            return '';
        }

        $html = '';

        foreach (iterator_to_array($content->querySelectorAll($selector)) as $node) {
            $html .= $document->saveHtml($node);
            $node->parentNode?->removeChild($node);
        }

        return $html;
    }

    /**
     * The date of the document as Y-m-d (or as printed when it cannot be
     * parsed): the configured element, looked for in the body then on the
     * page; with fallbacks, else a <time> in the body, else a short line in
     * the body that reads as a date, else the page's meta tags. An element
     * found is removed so the date is not printed twice.
     */
    private static function takeDate(HTMLDocument $document, Element $content, string $selector, bool $fallbacks): ?string
    {
        $node = null;

        if ($selector !== '') {
            $node = $content->querySelector($selector) ?? $document->querySelector($selector);
        }

        if ($fallbacks) {
            $node ??= $content->querySelector(self::DATE_FALLBACK) ?? self::dateLine($content);
        }

        if ($node instanceof Element) {
            $raw = trim((string) $node->getAttribute('datetime')) ?: (string) $node->textContent;
            $node->parentNode?->removeChild($node);
        } elseif ($fallbacks) {
            $meta = $document->querySelector(self::DATE_META);
            $raw = $meta instanceof Element ? (string) $meta->getAttribute('content') : '';
        } else {
            return null;
        }

        $raw = trim((string) preg_replace('/\s+/u', ' ', $raw));

        return $raw === '' ? null : self::dateText($raw);
    }

    /**
     * A printed date as Y-m-d; a date inside a longer line ("更新日:
     * 2026年9月10日", "10.09.2026 | News") is taken out of it.
     */
    private static function dateText(string $raw): string
    {
        if (preg_match('/(\d{4})[年.\/-](\d{1,2})[月.\/-](\d{1,2})/u', $raw, $ymd) === 1) {
            return sprintf('%04d-%02d-%02d', $ymd[1], $ymd[2], $ymd[3]);
        }

        return FetchUpdates::date($raw) ?? $raw;
    }

    /**
     * The first short element of the body whose text reads as a date.
     */
    private static function dateLine(Element $content): ?Element
    {
        foreach ($content->querySelectorAll(self::DATE_LINE_CANDIDATES) as $node) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));

            if ($text !== '' && mb_strlen($text) <= self::DATE_LINE_MAX_CHARS && preg_match(self::DATE_TEXT_PATTERN, $text) === 1) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Remove the links whose text says they only go back (最新ニュース一覧
     * ページへ戻る, "Back to the list").
     */
    private static function dropNavigationLinks(Element $content): void
    {
        foreach (iterator_to_array($content->querySelectorAll('a')) as $anchor) {
            $text = self::oneLine($anchor->textContent);

            if ($text !== '' && mb_strlen($text) <= self::NAVIGATION_LINK_MAX_CHARS && preg_match(self::NAVIGATION_LINK_PATTERN, $text) === 1) {
                $anchor->parentNode?->removeChild($anchor);
            }
        }
    }

    /**
     * The title of the document, printed once at the top: the heading in
     * the body that says what the update list said (taken out of the
     * body), else the body's own <h1> (taken out), else what the update
     * list said, else the page's <h1> (DARPA keeps it in the page header;
     * elsewhere it is the site logo or the section, hence last).
     */
    private static function takeTitle(HTMLDocument $document, Element $content, ?string $title): string
    {
        $listed = self::oneLine((string) $title);

        if ($listed !== '') {
            foreach ($content->querySelectorAll(self::TITLE_CANDIDATES) as $heading) {
                $text = self::oneLine($heading->textContent);

                if ($text !== '' && (str_starts_with($text, $listed) || str_starts_with($listed, $text))) {
                    $heading->parentNode?->removeChild($heading);

                    return $text;
                }
            }
        }

        $own = $content->querySelector('h1');

        if ($own instanceof Element) {
            $text = self::oneLine($own->textContent);
            $own->parentNode?->removeChild($own);

            return $text;
        }

        if ($listed !== '') {
            return $listed;
        }

        $page = $document->querySelector('h1');

        return $page instanceof Element ? self::oneLine($page->textContent) : '';
    }

    /**
     * Text with its whitespace collapsed to single spaces.
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Paragraphs that read as fixed text (copyright lines, notices) leave
     * the body for its end.
     *
     * @return array{0: string, 1: list<string>} the body and the paragraphs taken out of it
     */
    private static function splitFixedText(string $markdown): array
    {
        $body = [];
        $fixed = [];

        foreach (explode("\n\n", $markdown) as $block) {
            if (preg_match(self::FIXED_TEXT_PATTERN, ltrim($block, '*_ ')) === 1) {
                $fixed[] = $block;
            } else {
                $body[] = $block;
            }
        }

        return [implode("\n\n", $body), $fixed];
    }

    /**
     * Shift the headings so the highest one sits at the given level and the
     * others keep their distance from it.
     */
    private static function shiftHeadings(string $markdown, int $top): string
    {
        preg_match_all('/^(#{1,6}) /m', $markdown, $found);
        $levels = array_map(strlen(...), $found[1]);

        if ($levels === []) {
            return $markdown;
        }

        $shift = $top - min($levels);

        return $shift === 0 ? $markdown : (string) preg_replace_callback(
            '/^(#{1,6}) /m',
            fn (array $match): string => str_repeat('#', min(6, max(1, strlen($match[1]) + $shift))).' ',
            $markdown,
        );
    }

    /**
     * Whitespace the page's own indentation left behind: trailing on every
     * line (hard breaks included), leading on the first line of a block
     * (Markdown indents only inside lists and code). Headings left empty by
     * the removals are dropped, as are headings with nothing after them
     * (the heading of a search widget whose form was removed), and runs
     * of blank lines are closed up.
     */
    private static function tidy(string $markdown): string
    {
        $markdown = (string) preg_replace('/[ \t\x{00A0}]+$/mu', '', $markdown);
        $markdown = (string) preg_replace('/(^|\n\n)[ \t\x{00A0}]+/u', '$1', $markdown);
        $markdown = (string) preg_replace('/^\\\\?#{1,6}\s*$/m', '', $markdown);
        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));

        while (preg_match('/\n\n#{1,6} [^\n]*$/', $markdown) === 1) {
            $markdown = trim((string) preg_replace('/\n\n#{1,6} [^\n]*$/', '', $markdown));
        }

        return $markdown;
    }
}
