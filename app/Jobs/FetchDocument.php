<?php

namespace App\Jobs;

use App\Actions\FetchUpdates;
use App\Actions\ProposeDocumentSettings;
use App\Actions\ReadDocument;
use App\Models\Document;
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
 * document (status 取得中 / 取得済み / 失敗) so the screens can show it.
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
     * Queue the fetch for an update entry: its document row appears at once
     * as 取得中, whether it is new or being fetched again.
     */
    public static function queueFor(UpdateEntry $entry): Document
    {
        $document = Document::query()->updateOrCreate(
            ['update_entry_id' => $entry->id],
            ['title' => $entry->title, 'url' => $entry->url, 'status' => 'fetching', 'status_message' => null],
        );

        self::dispatch($document);

        return $document;
    }

    public function handle(ReadDocument $read, ProposeDocumentSettings $propose): void
    {
        $document = $this->document;
        $source = $document->updateEntry->source;

        try {
            // robots.txt is enforced by the global HTTP middleware (AppServiceProvider).
            $response = Http::withUserAgent(FetchUpdates::USER_AGENT)->timeout(30)->get($document->url)->throw();
            $body = $response->body();
            $format = str_contains(strtolower((string) $response->header('Content-Type')), 'application/pdf') || str_starts_with($body, '%PDF-') ? 'pdf' : 'html';

            // The original is kept as served, next to the other documents of the source.
            $path = "documents/{$source->id}/{$document->update_entry_id}.{$format}";
            Storage::disk('local')->put($path, $body);

            [$markdown, $message] = $format === 'pdf'
                ? [$read->pdf($body), null]
                : $this->markdown($body, $source, $document, $read, $propose);

            $document->update(['format' => $format, 'original_path' => $path, 'markdown' => $markdown, 'fetched_at' => now(), 'status' => 'fetched', 'status_message' => $message]);
        } catch (Throwable $exception) {
            $document->update(['status' => 'failed', 'status_message' => mb_substr($exception->getMessage(), 0, 1000)]);
        }
    }

    /**
     * Read the page with the source's document settings; when they are
     * missing or miss on this page, have new ones proposed and verified.
     *
     * @return array{0: string, 1: ?string} the Markdown and a note on how the settings came about
     */
    private function markdown(string $html, Source $source, Document $document, ReadDocument $read, ProposeDocumentSettings $propose): array
    {
        try {
            return [$read->html($html, $source->document_config ?? [], $document->url, $document->title), null];
        } catch (RuntimeException) {
            // Fall through: the settings need (re)making.
        }

        [$settings, $markdown] = self::verify($html, $propose($html, $document->url), $document, $read);
        $source->update(['document_config' => $settings]);

        return [$markdown, __('Document settings proposed by the agent and verified on this page (content: :content).', ['content' => $settings['content']])];
    }

    /**
     * Apply the proposal to the page; when its content selector yields no
     * usable body, generic selectors are tried in a fixed order, and the
     * settings that actually worked are what gets saved.
     *
     * @param  array{content: string, date: string, remove: string, fixed_text: string}  $proposal
     * @return array{0: array{content: string, date: string, remove: string, fixed_text: string}, 1: string}
     */
    private static function verify(string $html, array $proposal, Document $document, ReadDocument $read): array
    {
        foreach (array_unique(array_filter([$proposal['content'], ...self::FALLBACK_CONTENT])) as $content) {
            $settings = [...$proposal, 'content' => $content];

            try {
                return [$settings, $read->html($html, $settings, $document->url, $document->title)];
            } catch (Throwable) {
                // A selector that misses, or is not valid CSS: try the next one.
                continue;
            }
        }

        throw new RuntimeException(__('Neither the agent\'s proposal nor the generic selectors found the body of this page. Enter the content selector in the document settings of the source.'));
    }
}
