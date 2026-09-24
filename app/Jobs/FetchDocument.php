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
 * 文書を取得 (UI: "Fetch document", stage 2.2 of docs/HANDOVER.md): fetch
 * the page behind a document in the background, keep the original
 * file, and read it into Markdown with the source's document settings.
 * When the source has no settings yet, or they no longer match (the site
 * changed its layout), the agent proposes new ones, which are verified on
 * this page before they are saved to the source. The outcome lands on the
 * document (status 取得中 / 取得済み / 失敗) so the screens can show it, and a
 * fetched document goes straight on to the 意味フィルタ (ApplySemanticFilter)
 * and from there to the スクリーニング (ScreenDocument).
 * A source with a link to the full text (全文へのリンク) has its document's
 * page stand for a summary (arXiv's abstract page): the full text it links
 * to is what is kept and read. A document that was screened and adopted
 * on the feed's summary is not screened again on its full text.
 */
class FetchDocument implements ShouldQueue
{
    use Queueable;

    /** Generic selectors tried when the proposed content selector finds nothing usable. */
    public const FALLBACK_CONTENT = ['article', 'main', '[role="main"]'];

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public Document $document) {}

    /**
     * Queue the fetch for a document: it shows as 取得中 at once,
     * whether it is fetched for the first time or again.
     */
    public static function queueFor(Document $document): Document
    {
        $document->update(['status' => 'fetching', 'status_message' => null]);

        self::dispatch($document);

        return $document;
    }

    public function handle(ReadDocument $read, ProposeDocumentSettings $propose, FetchFavicon $favicon): void
    {
        $document = $this->document;
        $source = $document->source;
        $adoptedOnSummary = $document->format === 'feed' && $document->decision() === 'adopt';

        // A summary the semantic filter has left out since its full text was queued is not worth the fetch.
        if ($document->format === 'feed' && $document->isLeftOut()) {
            $document->update(['status' => 'fetched', 'status_message' => __('The semantic filter left this document out; its full text was not fetched.')]);

            return;
        }

        try {
            [$body, $format] = self::get($document->url);

            // A source still without its icon gets it from this page, which advertises the same one as the rest of the site.
            if ($format === 'html') {
                $favicon($source, $body);
            }

            // The page may only stand for the document: the full text it links to is what is read.
            $url = $format === 'html' ? self::fullTextUrl($body, (string) $source->full_text_link, $document->url) : null;

            if ($url !== null) {
                [$body, $format] = self::get($url);
            }

            // The original is kept as served, next to the other documents of the source.
            $path = "documents/{$source->id}/{$document->id}.{$format}";
            Storage::disk('local')->put($path, $body);

            [$markdown, $message] = $format === 'pdf'
                ? [$read->pdf($body, $document->title), null]
                : $this->markdown($body, $url ?? $document->url, $source, $document, $read, $propose);

            $document->update(['format' => $format, 'original_path' => $path, 'markdown' => $markdown, 'fetched_at' => now(), 'status' => 'fetched', 'status_message' => $message]);

            // The semantic filter and then the gate follow the fetch on their own; an excluded document fetched by hand, or one already adopted on its summary, is left out of them.
            if ($document->excluded_by === null && ! $adoptedOnSummary) {
                ApplySemanticFilter::queueFor($document);
            }
        } catch (Throwable $exception) {
            // A database error quotes the bindings, bytes that are not UTF-8 included: the message is made storable or the document would stay 取得中.
            $document->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);
        }
    }

    /**
     * The body served at a URL and whether it is a PDF or HTML.
     * robots.txt is enforced by the global HTTP middleware (AppServiceProvider).
     *
     * @return array{0: string, 1: 'html'|'pdf'}
     */
    private static function get(string $url): array
    {
        $response = Crawler::client(30)->get($url)->throw();
        $body = $response->body();

        return [$body, str_contains(strtolower((string) $response->header('Content-Type')), 'application/pdf') || str_starts_with($body, '%PDF-') ? 'pdf' : 'html'];
    }

    /**
     * The link to the full text on a document's page per the source's
     * 全文へのリンク: the selectors are tried in the order written, one per
     * line (arXiv: the HTML version, else the PDF), and the first that
     * finds a link wins. Null when the source has none or none matches,
     * and the page itself is read.
     */
    public static function fullTextUrl(string $html, string $selectors, string $pageUrl): ?string
    {
        $lines = array_filter(array_map(trim(...), explode("\n", $selectors)));

        if ($lines === []) {
            return null;
        }

        $page = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, 'UTF-8');

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
     * Read the page with the source's document settings; when they are
     * missing or miss on this page, have new ones proposed and verified.
     *
     * @return array{0: string, 1: ?string} the Markdown and a note on how the settings came about
     */
    private function markdown(string $html, string $url, Source $source, Document $document, ReadDocument $read, ProposeDocumentSettings $propose): array
    {
        try {
            return [$read->html($html, $source->document_settings ?? [], $url, $document->title), null];
        } catch (RuntimeException) {
            // Fall through: the settings need (re)making.
        }

        [$settings, $markdown] = self::verify($html, $propose($html, $url), $document, $read, $url);
        $source->update(['document_settings' => $settings]);

        return [$markdown, __('Document settings proposed by the agent and verified on this page (content: :content).', ['content' => $settings['content']])];
    }

    /**
     * Apply the proposal to the page, ids and classes numbered per page
     * generalised first; when its content selector yields no usable body,
     * the proposal as made and then generic selectors are tried in a fixed
     * order, and the settings that actually worked are what gets saved.
     * The URL is the page's when it is not the document's own (a full text).
     *
     * @param  array{content: string, date: string, remove: string, fixed_text: string}  $proposal
     * @return array{0: array{content: string, date: string, remove: string, fixed_text: string}, 1: string}
     */
    public static function verify(string $html, array $proposal, Document $document, ReadDocument $read, ?string $url = null): array
    {
        $general = array_map(self::generalise(...), $proposal);

        foreach (array_unique(array_filter([$general['content'], $proposal['content'], ...self::FALLBACK_CONTENT])) as $content) {
            $settings = [...$general, 'content' => $content];

            try {
                return [$settings, $read->html($html, $settings, $url ?? $document->url, $document->title)];
            } catch (Throwable) {
                // A selector that misses, or is not valid CSS: try the next one.
                continue;
            }
        }

        throw new RuntimeException(__('Neither the agent\'s proposal nor the generic selectors found the body of this page. Enter the content selector in the document settings of the source.'));
    }

    /**
     * A selector that names this page's number (日立: #content-17863846,
     * one per article) would match no other page of the site: the number
     * is dropped for a prefix match on the id or class.
     */
    private static function generalise(string $selector): string
    {
        $selector = (string) preg_replace('/#([A-Za-z_][\w-]*?[-_])\d{4,}(?![\w-])/', '[id^="$1"]', $selector);

        return (string) preg_replace('/\.([A-Za-z_][\w-]*?[-_])\d{4,}(?![\w-])/', '[class*="$1"]', $selector);
    }
}
