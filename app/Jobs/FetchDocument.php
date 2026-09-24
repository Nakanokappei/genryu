<?php

namespace App\Jobs;

use App\Actions\FetchFavicon;
use App\Actions\ProposeDocumentSettings;
use App\Actions\ReadDocument;
use App\Crawl\Crawler;
use App\Crawl\Url;
use App\Models\Document;
use App\Models\Source;
use App\Support\ErrorMessage;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * 文書を取得 (UI: "Fetch document"): fetch a document's page (or the full
 * text it links to), keep the original and read it into Markdown.
 */
class FetchDocument implements ShouldQueue
{
    use Queueable;

    /** Generic selectors tried when the proposed content selector finds nothing usable. */
    public const FALLBACK_CONTENT = ['article', 'main', '[role="main"]'];

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public Document $document) {}

    /** Mark the document 取得中 and queue the fetch. */
    public static function queueFor(Document $document): Document
    {
        $document->update(['status' => 'fetching', 'status_message' => null]);

        self::dispatch($document);

        return $document;
    }

    /** Fetch, keep and read the document, then queue the semantic filter; a failure lands on the document. */
    public function handle(ReadDocument $read, ProposeDocumentSettings $propose, FetchFavicon $favicon): void
    {
        $document = $this->document;
        $source = $document->source;
        $adoptedOnSummary = $document->format === 'feed' && $document->decision() === 'adopt';

        // A feed summary the semantic filter left out is not fetched.
        if ($document->format === 'feed' && $document->isLeftOut()) {
            $document->update(['status' => 'fetched', 'status_message' => __('The semantic filter left this document out; its full text was not fetched.')]);

            return;
        }

        try {
            [$body, $format] = self::get($document->url);

            // The site's icon, from this page.
            if ($format === 'html') {
                $favicon($source, $body);
            }

            // The full text the page links to (全文へのリンク), if any.
            $url = $format === 'html' ? self::fullTextUrl($body, (string) $source->full_text_link, $document->url) : null;

            // Read the full text instead of the page.
            if ($url !== null) {
                [$body, $format] = self::get($url);
            }

            // The original, as served.
            $path = "documents/{$source->id}/{$document->id}.{$format}";
            Storage::disk('local')->put($path, $body);

            [$markdown, $message] = $format === 'pdf'
                ? [$read->pdf($body, $document->title), null]
                : $this->markdown($body, $url ?? $document->url, $source, $document, $read, $propose);

            $document->update(['format' => $format, 'original_path' => $path, 'markdown' => $markdown, 'fetched_at' => now(), 'status' => 'fetched', 'status_message' => $message]);

            // On to the semantic filter, unless excluded or already adopted on its summary.
            if ($document->excluded_by === null && ! $adoptedOnSummary) {
                ApplySemanticFilter::queueFor($document);
            }
        } catch (Throwable $exception) {
            // ErrorMessage::of makes the message storable (a database error may quote non-UTF-8 bytes).
            $document->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);
        }
    }

    /**
     * The body served at a URL and whether it is a PDF or HTML.
     *
     * @return array{0: string, 1: 'html'|'pdf'}
     */
    private static function get(string $url): array
    {
        $response = Crawler::client(30)->get($url)->throw();
        $body = $response->body();

        return [$body, str_contains(strtolower((string) $response->header('Content-Type')), 'application/pdf') || str_starts_with($body, '%PDF-') ? 'pdf' : 'html'];
    }

    /** The full-text link per 全文へのリンク (one selector per line, first match wins), or null. */
    public static function fullTextUrl(string $html, string $selectors, string $pageUrl): ?string
    {
        $lines = array_filter(array_map(trim(...), explode("\n", $selectors)));

        // No selectors: the page itself is read.
        if ($lines === []) {
            return null;
        }

        $page = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, 'UTF-8');

        // The first selector that finds a non-empty href.
        foreach ($lines as $selector) {
            $link = $page->querySelector($selector);
            $href = $link instanceof Element ? trim((string) $link->getAttribute('href')) : '';

            if ($href !== '') {
                return Url::absolute($href, $pageUrl);
            }
        }

        return null;
    }

    /**
     * Read the page with the source's document settings, else with new ones proposed and verified.
     *
     * @return array{0: string, 1: ?string} the Markdown and a note on how the settings came about
     */
    private function markdown(string $html, string $url, Source $source, Document $document, ReadDocument $read, ProposeDocumentSettings $propose): array
    {
        try {
            return [$read->html($html, $source->document_settings ?? [], $url, $document->title), null];
        } catch (RuntimeException) {
            // The settings are missing or miss: propose new ones.
        }

        [$settings, $markdown] = self::verify($html, $propose($html, $url), $document, $read, $url);
        $source->update(['document_settings' => $settings]);

        return [$markdown, __('Document settings proposed by the agent and verified on this page (content: :content).', ['content' => $settings['content']])];
    }

    /**
     * Apply the proposal (generalised, then as made, then FALLBACK_CONTENT)
     * and return the first settings that yield a body. $url is the full text's, if any.
     *
     * @param  array{content: string, date: string, remove: string, fixed_text: string}  $proposal
     * @return array{0: array{content: string, date: string, remove: string, fixed_text: string}, 1: string}
     */
    public static function verify(string $html, array $proposal, Document $document, ReadDocument $read, ?string $url = null): array
    {
        $general = array_map(self::generalise(...), $proposal);

        // Each content selector in turn until one reads a body.
        foreach (array_unique(array_filter([$general['content'], $proposal['content'], ...self::FALLBACK_CONTENT])) as $content) {
            $settings = [...$general, 'content' => $content];

            try {
                return [$settings, $read->html($html, $settings, $url ?? $document->url, $document->title)];
            } catch (Throwable) {
                // Misses, or not valid CSS: try the next.
                continue;
            }
        }

        throw new RuntimeException(__('Neither the agent\'s proposal nor the generic selectors found the body of this page. Enter the content selector in the document settings of the source.'));
    }

    /** Replace a per-page number in an id or class (#content-17863846) with a prefix match. */
    private static function generalise(string $selector): string
    {
        $selector = (string) preg_replace('/#([A-Za-z_][\w-]*?[-_])\d{4,}(?![\w-])/', '[id^="$1"]', $selector);

        return (string) preg_replace('/\.([A-Za-z_][\w-]*?[-_])\d{4,}(?![\w-])/', '[class*="$1"]', $selector);
    }
}
