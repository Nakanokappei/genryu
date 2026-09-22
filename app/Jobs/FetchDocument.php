<?php

namespace App\Jobs;

use App\Actions\FetchFavicon;
use App\Actions\FetchUpdates;
use App\Actions\ProposeDocumentSettings;
use App\Actions\ReadDocument;
use App\Models\Source;
use App\Models\UpdateEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * 文書を取得 (UI: "Fetch document", stage 2.2 of docs/HANDOVER.md): fetch
 * the page behind an update entry in the background, keep the original
 * file, and read it into Markdown with the source's document settings.
 * When the source has no settings yet, or they no longer match (the site
 * changed its layout), the agent proposes new ones, which are verified on
 * this page before they are saved to the source. The outcome lands on the
 * update entry (status 取得中 / 取得済み / 失敗) so the screens can show it.
 */
class FetchDocument implements ShouldQueue
{
    use Queueable;

    /** Generic selectors tried when the proposed content selector finds nothing usable. */
    public const FALLBACK_CONTENT = ['article', 'main', '[role="main"]'];

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public UpdateEntry $entry) {}

    /**
     * Queue the fetch for an update entry: it shows as 取得中 at once,
     * whether it is fetched for the first time or again.
     */
    public static function queueFor(UpdateEntry $entry): UpdateEntry
    {
        $entry->update(['status' => 'fetching', 'status_message' => null]);

        self::dispatch($entry);

        return $entry;
    }

    public function handle(ReadDocument $read, ProposeDocumentSettings $propose, FetchFavicon $favicon): void
    {
        $entry = $this->entry;
        $source = $entry->source;

        try {
            // robots.txt is enforced by the global HTTP middleware (AppServiceProvider).
            $response = Http::withUserAgent(FetchUpdates::USER_AGENT)->timeout(30)->get($entry->url)->throw();
            $body = $response->body();
            $format = str_contains(strtolower((string) $response->header('Content-Type')), 'application/pdf') || str_starts_with($body, '%PDF-') ? 'pdf' : 'html';

            // A source still without its icon gets it from this page, which advertises the same one as the rest of the site.
            if ($format === 'html') {
                $favicon($source, $body);
            }

            // The original is kept as served, next to the other documents of the source.
            $path = "documents/{$source->id}/{$entry->id}.{$format}";
            Storage::disk('local')->put($path, $body);

            [$markdown, $message] = $format === 'pdf'
                ? [$read->pdf($body), null]
                : $this->markdown($body, $source, $entry, $read, $propose);

            $entry->update(['format' => $format, 'original_path' => $path, 'markdown' => $markdown, 'fetched_at' => now(), 'status' => 'fetched', 'status_message' => $message]);
        } catch (Throwable $exception) {
            $entry->update(['status' => 'failed', 'status_message' => mb_substr($exception->getMessage(), 0, 1000)]);
        }
    }

    /**
     * Read the page with the source's document settings; when they are
     * missing or miss on this page, have new ones proposed and verified.
     *
     * @return array{0: string, 1: ?string} the Markdown and a note on how the settings came about
     */
    private function markdown(string $html, Source $source, UpdateEntry $entry, ReadDocument $read, ProposeDocumentSettings $propose): array
    {
        try {
            return [$read->html($html, $source->document_config ?? [], $entry->url, $entry->title), null];
        } catch (RuntimeException) {
            // Fall through: the settings need (re)making.
        }

        [$settings, $markdown] = self::verify($html, $propose($html, $entry->url), $entry, $read);
        $source->update(['document_config' => $settings]);

        return [$markdown, __('Document settings proposed by the agent and verified on this page (content: :content).', ['content' => $settings['content']])];
    }

    /**
     * Apply the proposal to the page, ids and classes numbered per page
     * generalised first; when its content selector yields no usable body,
     * the proposal as made and then generic selectors are tried in a fixed
     * order, and the settings that actually worked are what gets saved.
     *
     * @param  array{content: string, date: string, remove: string, fixed_text: string}  $proposal
     * @return array{0: array{content: string, date: string, remove: string, fixed_text: string}, 1: string}
     */
    private static function verify(string $html, array $proposal, UpdateEntry $entry, ReadDocument $read): array
    {
        $general = array_map(self::generalise(...), $proposal);

        foreach (array_unique(array_filter([$general['content'], $proposal['content'], ...self::FALLBACK_CONTENT])) as $content) {
            $settings = [...$general, 'content' => $content];

            try {
                return [$settings, $read->html($html, $settings, $entry->url, $entry->title)];
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
    public static function generalise(string $selector): string
    {
        $selector = (string) preg_replace('/#([A-Za-z_][\w-]*?[-_])\d{4,}(?![\w-])/', '[id^="$1"]', $selector);

        return (string) preg_replace('/\.([A-Za-z_][\w-]*?[-_])\d{4,}(?![\w-])/', '[class*="$1"]', $selector);
    }
}
