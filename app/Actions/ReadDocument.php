<?php

namespace App\Actions;

use App\Crawl\PublishedDate;
use App\Crawl\Url;
use App\Pdf\PdfMarkdown;
use App\Pdf\PdfParser;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Str;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use RuntimeException;

/**
 * Reads a fetched document (HTML with the source's document settings, PDF,
 * or a feed summary) into Markdown: title, date, body, then fixed text
 * after a `---`. No model.
 */
class ReadDocument
{
    /** Document settings keys (UI 本文 / 日付 / 除外 / 固定テキスト), each a CSS selector. */
    public const DOCUMENT_SETTING_KEYS = ['content', 'date', 'remove', 'fixed_text'];

    /** Below this, the settings are taken to have missed the body. */
    public const MINIMUM_CHARS = 100;

    /** Heading level of the title; body headings start one below. */
    private const TOP_LEVEL = 1;

    /** Elements in the body that may hold the title. */
    private const TITLE_CANDIDATES = 'h1, h2, h3, h4';

    /** Text of a link that only leads back to a list or top page. */
    private const NAVIGATION_LINK_PATTERN = '/(へ戻る|に戻る|^戻る$|一覧へ$|^back to\b|^return to\b|^go back\b|^zurück\b|^retour\b)/iu';

    /** Longest text such a link may have. */
    private const NAVIGATION_LINK_MAX_CHARS = 40;

    /** Always removed from the body. */
    private const ALWAYS_REMOVED = 'script, style, noscript, iframe, svg, form, button, nav, template, [role="navigation"], .breadcrumb, .breadcrumbs, .pager, .pagination';

    /** First fallback for the date when the settings give none. */
    private const DATE_FALLBACK = 'time[datetime], time';

    /** Elements that may be a date line on their own. */
    private const DATE_LINE_CANDIDATES = 'h2, h3, h4, h5, h6, p, span, div, li';

    /** Longest text a date line may have. */
    private const DATE_LINE_MAX_CHARS = 40;

    /** What a printed date looks like: 2026年9月10日, 2026-09-10, 10.09.2026, June 26, 2026, 26 June 2026. */
    private const DATE_TEXT_PATTERN = '/\d{4}[年.\/-]\d{1,2}[月.\/-]\d{1,2}|\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}\b|\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{1,2},? \d{4}\b|\b\d{1,2}\.? (jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.? \d{4}\b/iu';

    /** Meta tags that may carry the date, the last fallback. */
    private const DATE_META = 'meta[property="article:published_time"], meta[name="date"], meta[name="pubdate"]';

    /** A paragraph starting like this is moved to the fixed text. */
    private const FIXED_TEXT_PATTERN = '/^(©|\(c\)|copyright\b|all rights reserved|無断転載|著作権|※|掲載の(データ|情報|内容)は|発表(当時|時点)の)/iu';

    /**
     * An HTML page as Markdown; throws when the settings are missing, match nothing or too little.
     *
     * @param  array<string, mixed>  $config
     */
    public function html(string $html, array $config, string $url, ?string $title = null): string
    {
        $selector = trim((string) ($config['content'] ?? ''));

        // No settings yet.
        if ($selector === '') {
            throw new RuntimeException(__('This source has no document settings yet.'));
        }

        // UTF-8 when the bytes are; otherwise the parser detects the charset.
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, mb_check_encoding($html, 'UTF-8') ? 'UTF-8' : null);
        $content = $document->querySelector($selector);

        // Content selector matches nothing.
        if (! $content instanceof Element) {
            throw new RuntimeException(__('The document settings matched nothing on this page.'));
        }

        // Configured date first: it may sit in a block removed below.
        $date = self::takeDate($document, $content, trim((string) ($config['date'] ?? '')), fallbacks: false);

        // Remove ALWAYS_REMOVED, then the configured remove selectors.
        foreach (array_filter([self::ALWAYS_REMOVED, trim((string) ($config['remove'] ?? ''))]) as $dropped) {
            self::takeOut($document, $content, $dropped);
        }

        // Remove back-to-list links.
        self::dropNavigationLinks($content);

        // Fallback date, then take out the fixed text.
        $date ??= self::takeDate($document, $content, '', fallbacks: true);
        $fixedHtml = self::takeOut($document, $content, trim((string) ($config['fixed_text'] ?? '')));

        // Title, taken out of the body when found there.
        $heading = self::takeTitle($document, $content, $title);

        // Make link and image URLs absolute.
        foreach ([['a', 'href'], ['img', 'src']] as [$tag, $attribute]) {
            foreach ($content->querySelectorAll("{$tag}[{$attribute}]") as $node) {
                $node->setAttribute($attribute, Url::absolute(trim((string) $node->getAttribute($attribute)), $url));
            }
        }

        $converter = new HtmlConverter(['header_style' => 'atx', 'strip_tags' => true, 'strip_placeholder_links' => true]);
        $converter->getEnvironment()->addConverter(new TableConverter);
        $markdown = self::tidy($converter->convert($content->innerHTML));

        // Too short to be the body.
        if (mb_strlen($markdown) < self::MINIMUM_CHARS) {
            throw new RuntimeException(__('The document settings matched too little on this page (:count characters).', ['count' => mb_strlen($markdown)]));
        }

        // Fixed text by pattern joins the configured fixed text.
        [$markdown, $notices] = self::splitFixedText($markdown);
        $fixed = self::tidy(implode("\n\n", [$fixedHtml !== '' ? $converter->convert($fixedHtml) : '', ...$notices]));

        return implode("\n\n", array_filter([
            $heading !== '' ? str_repeat('#', self::TOP_LEVEL)." {$heading}" : '',
            $date,
            self::shiftHeadings($markdown, self::TOP_LEVEL + 1),
            $fixed !== '' ? "---\n\n{$fixed}" : '',
        ]));
    }

    /** A PDF as Markdown in the same shape, via App\Pdf\PdfMarkdown. */
    public function pdf(string $bytes, ?string $title = null): string
    {
        ['title' => $heading, 'date' => $date, 'body' => $body] = (new PdfMarkdown)((new PdfParser)->parseContent($bytes), $title);

        // No text in the PDF.
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

    /** A feed's summary as Markdown (title, date, summary), standing in until the full text is fetched. */
    public static function summary(string $title, ?string $date, string $summary): string
    {
        return implode("\n\n", array_filter([
            str_repeat('#', self::TOP_LEVEL)." {$title}",
            $date !== null ? self::dateText($date) : '',
            self::tidy($summary),
        ]));
    }

    /** Removes what a selector matches in the body and returns its HTML. */
    private static function takeOut(HTMLDocument $document, Element $content, string $selector): string
    {
        // No selector: nothing to take out.
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
     * The date as Y-m-d (or as printed): the configured element, then with
     * $fallbacks a <time>, a date line, the meta tags. The element is removed.
     */
    private static function takeDate(HTMLDocument $document, Element $content, string $selector, bool $fallbacks): ?string
    {
        $node = null;

        // Configured element, in the body then on the page.
        if ($selector !== '') {
            $node = $content->querySelector($selector) ?? $document->querySelector($selector);
        }

        // Fallback elements in the body.
        if ($fallbacks) {
            $node ??= $content->querySelector(self::DATE_FALLBACK) ?? self::dateLine($content);
        }

        // From the element, else from the meta tags.
        if ($node instanceof Element) {
            $raw = trim((string) $node->getAttribute('datetime')) ?: (string) $node->textContent;
            $node->parentNode?->removeChild($node);
        } elseif ($fallbacks) {
            $meta = $document->querySelector(self::DATE_META);
            $raw = $meta instanceof Element ? (string) $meta->getAttribute('content') : '';
        } else {
            return null;
        }

        $raw = Str::squish($raw);

        return $raw === '' ? null : self::dateText($raw);
    }

    /** A printed date, possibly inside a longer line, as Y-m-d. */
    private static function dateText(string $raw): string
    {
        // Year-month-day order.
        if (preg_match('/(\d{4})[年.\/-](\d{1,2})[月.\/-](\d{1,2})/u', $raw, $ymd) === 1) {
            return sprintf('%04d-%02d-%02d', $ymd[1], $ymd[2], $ymd[3]);
        }

        return PublishedDate::parse($raw) ?? $raw;
    }

    /** The first short element of the body whose text reads as a date. */
    private static function dateLine(Element $content): ?Element
    {
        foreach ($content->querySelectorAll(self::DATE_LINE_CANDIDATES) as $node) {
            $text = Str::squish($node->textContent);

            // Short and date-looking.
            if ($text !== '' && mb_strlen($text) <= self::DATE_LINE_MAX_CHARS && preg_match(self::DATE_TEXT_PATTERN, $text) === 1) {
                return $node;
            }
        }

        return null;
    }

    /** Removes links whose text says they only go back. */
    private static function dropNavigationLinks(Element $content): void
    {
        foreach (iterator_to_array($content->querySelectorAll('a')) as $anchor) {
            $text = Str::squish($anchor->textContent);

            // Short and back-to-list wording.
            if ($text !== '' && mb_strlen($text) <= self::NAVIGATION_LINK_MAX_CHARS && preg_match(self::NAVIGATION_LINK_PATTERN, $text) === 1) {
                $anchor->parentNode?->removeChild($anchor);
            }
        }
    }

    /**
     * The title: a body heading matching the listed title (taken out), else
     * the body's <h1> (taken out), else the listed title, else the page's
     * <h1> (last, as it is often the site logo).
     */
    private static function takeTitle(HTMLDocument $document, Element $content, ?string $title): string
    {
        $listed = Str::squish((string) $title);

        // A body heading matching the listed title.
        if ($listed !== '') {
            foreach ($content->querySelectorAll(self::TITLE_CANDIDATES) as $heading) {
                $text = Str::squish($heading->textContent);

                if ($text !== '' && (str_starts_with($text, $listed) || str_starts_with($listed, $text))) {
                    $heading->parentNode?->removeChild($heading);

                    return $text;
                }
            }
        }

        $own = $content->querySelector('h1');

        // The body's own <h1>.
        if ($own instanceof Element) {
            $text = Str::squish($own->textContent);
            $own->parentNode?->removeChild($own);

            return $text;
        }

        // The listed title.
        if ($listed !== '') {
            return $listed;
        }

        $page = $document->querySelector('h1');

        return $page instanceof Element ? Str::squish($page->textContent) : '';
    }

    /**
     * Splits paragraphs matching FIXED_TEXT_PATTERN out of the body.
     *
     * @return array{0: string, 1: list<string>} the body and the paragraphs taken out of it
     */
    private static function splitFixedText(string $markdown): array
    {
        $body = [];
        $fixed = [];

        // Each paragraph to one side or the other.
        foreach (explode("\n\n", $markdown) as $block) {
            if (preg_match(self::FIXED_TEXT_PATTERN, ltrim($block, '*_ ')) === 1) {
                $fixed[] = $block;
            } else {
                $body[] = $block;
            }
        }

        return [implode("\n\n", $body), $fixed];
    }

    /** Shifts headings so the highest sits at $top, keeping relative levels. */
    private static function shiftHeadings(string $markdown, int $top): string
    {
        preg_match_all('/^(#{1,6}) /m', $markdown, $found);
        $levels = array_map(strlen(...), $found[1]);

        // No headings.
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
     * Cleans Markdown: trailing and block-leading whitespace, empty headings,
     * headings at the end with nothing after them, runs of blank lines.
     */
    private static function tidy(string $markdown): string
    {
        $markdown = (string) preg_replace('/[ \t\x{00A0}]+$/mu', '', $markdown);
        $markdown = (string) preg_replace('/(^|\n\n)[ \t\x{00A0}]+/u', '$1', $markdown);
        $markdown = (string) preg_replace('/^\\\\?#{1,6}\s*$/m', '', $markdown);
        $markdown = trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));

        // Drop trailing headings with nothing after them.
        while (preg_match('/\n\n#{1,6} [^\n]*$/', $markdown) === 1) {
            $markdown = trim((string) preg_replace('/\n\n#{1,6} [^\n]*$/', '', $markdown));
        }

        return $markdown;
    }
}
