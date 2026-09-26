<?php

namespace App\Actions;

use App\Crawl\Crawler;
use App\Crawl\Feed;
use App\Crawl\HtmlList;
use App\Crawl\JsonList;
use App\Crawl\PublishedDate;
use App\Crawl\Url;
use App\Jobs\ApplySemanticFilter;
use App\Jobs\FetchDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Source;
use RuntimeException;

/**
 * 更新リストを取得 (UI "Fetch updates"): reads a source's JSON list, HTML
 * list or feed (App\Crawl) and stores the listed documents. No model.
 */
class FetchUpdates
{
    /** At most this many documents of one read are sent to be fetched, newest first: 5 a day for 7 days. */
    public const MAX_FETCHES = 35;

    /** The last HTML page of the source read during a fetch, for its favicon. */
    private ?string $pageHtml = null;

    /** Documents of this read sent to be fetched, and those listed beyond MAX_FETCHES. */
    private int $fetches = 0;

    private int $held = 0;

    public function __construct(private FetchFavicon $favicon) {}

    /**
     * Reads the source by its configured kind of list, then refreshes its favicon.
     *
     * @return array{feed_url: ?string, pages: int, added: int, existing: int, held: int}
     */
    public function __invoke(Source $source): array
    {
        $this->pageHtml = null;
        $this->fetches = 0;
        $this->held = 0;
        $json = $source->json_list_settings ?? [];
        $config = $source->html_list_settings ?? [];

        $result = match (true) {
            ($json['url'] ?? '') !== '' => $this->fromJsonList($source, $json),
            ($config['item'] ?? '') !== '' => $this->fromHtmlList($source, $config),
            default => $this->fromFeed($source),
        };

        // Fetch or recheck the favicon while at the site.
        ($this->favicon)($source, $this->pageHtml, checkAgain: true);

        return [...$result, 'held' => $this->held];
    }

    /**
     * Reads the JSON list, up to max_items entries.
     *
     * @param  array<string, mixed>  $config
     * @return array{feed_url: null, pages: int, added: int, existing: int}
     */
    private function fromJsonList(Source $source, array $config): array
    {
        $entries = JsonList::entries(Crawler::get((string) $config['url'])->body(), $config, $source->url);

        // Settings match nothing.
        if ($entries === []) {
            throw new RuntimeException(__('The JSON list settings matched nothing.'));
        }

        $counts = $this->store($source, array_slice($entries, 0, max(1, (int) ($config['max_items'] ?? JsonList::DEFAULT_MAX_ITEMS))));
        $source->update(['feed_url' => null, 'updates_fetched_at' => now()]);

        return ['feed_url' => null, 'pages' => 1, ...$counts];
    }

    /**
     * Discovers the feed from the source page and reads it.
     *
     * @return array{feed_url: string, pages: int, added: int, existing: int}
     */
    private function fromFeed(Source $source): array
    {
        $this->pageHtml = Crawler::get($source->url)->body();
        [$feedUrl, $body] = Feed::discover($source->url, $this->pageHtml)
            ?? throw new RuntimeException(__('No RSS or Atom feed found. Fill in the HTML list settings to read this page.'));

        $counts = $this->store($source, Feed::entries($body));
        $source->update(['feed_url' => $feedUrl, 'updates_fetched_at' => now()]);

        return ['feed_url' => $feedUrl, 'pages' => 1, ...$counts];
    }

    /**
     * Reads the HTML list page by page, while a page adds something new, up to max_pages.
     *
     * @param  array<string, mixed>  $config
     * @return array{feed_url: null, pages: int, added: int, existing: int}
     */
    private function fromHtmlList(Source $source, array $config): array
    {
        $maxPages = max(1, (int) ($config['max_pages'] ?? 1));
        $url = $source->url;
        $pages = 0;
        $added = 0;
        $existing = 0;

        // Follow next links until nothing new or the page limit.
        while ($url !== null && $pages < $maxPages) {
            $body = Crawler::get($url)->body();
            $this->pageHtml ??= $body;
            $document = HtmlList::document($body);
            $pages++;

            $counts = $this->store($source, HtmlList::entries($document, $config, $url));
            $added += $counts['added'];
            $existing += $counts['existing'];

            $url = $counts['added'] > 0 ? HtmlList::nextPage($document, $config, $url) : null;
        }

        // Settings match nothing on the first page.
        if ($pages === 1 && $added + $existing === 0) {
            throw new RuntimeException(__('The HTML list settings matched nothing on this page.'));
        }

        $source->update(['feed_url' => null, 'updates_fetched_at' => now()]);

        return ['feed_url' => null, 'pages' => $pages, 'added' => $added, 'existing' => $existing];
    }

    /**
     * Stores the listed entries and queues each new document: 対象外 by the
     * title filter, the semantic filter for a feed summary (全文へのリンク),
     * otherwise a fetch.
     *
     * @param  list<array{title: string, url: string, published_at: ?string, summary?: string}>  $entries
     * @return array{added: int, existing: int}
     */
    private function store(Source $source, array $entries): array
    {
        $added = 0;
        $existing = 0;
        $readsSummaries = trim((string) $source->full_text_link) !== '';
        $rules = EditorialPolicy::excludeKeywords();

        // Newest first when every entry is dated, so the fetch limit keeps the newest; else the list's own order.
        if (array_all($entries, fn (array $entry): bool => $entry['published_at'] !== null)) {
            usort($entries, fn (array $a, array $b): int => strcmp((string) $b['published_at'], (string) $a['published_at']));
        }

        foreach ($entries as $entry) {
            // Only http(s) links are documents (a javascript: or file: link is not).
            if (! Url::isWeb($entry['url'])) {
                continue;
            }

            // Listed by another source already: counts as existing.
            if (Document::query()->where('url', $entry['url'])->where('source_id', '!=', $source->id)->exists()) {
                $existing++;

                continue;
            }

            $summary = trim($entry['summary'] ?? '');
            $excludedBy = EditorialPolicy::excludedBy($entry['title'], $rules);
            $readsSummary = $readsSummaries && $excludedBy === null && $summary !== '';

            $created = Document::query()->firstOrCreate(
                ['source_id' => $source->id, 'url' => $entry['url']],
                [
                    'title' => $entry['title'], 'published_at' => $entry['published_at'], 'published_has_time' => PublishedDate::hasTime($entry['published_at']), 'excluded_by' => $excludedBy,
                    ...($readsSummary ? ['format' => 'feed', 'markdown' => ReadDocument::summary($entry['title'], $entry['published_at'], $summary), 'fetched_at' => now(), 'status' => 'fetched'] : []),
                ],
            );

            // New: queue its next step.
            if ($created->wasRecentlyCreated) {
                $added++;

                match (true) {
                    $created->excluded_by !== null => null,
                    $created->format === 'feed' => ApplySemanticFilter::queueFor($created),
                    $this->fetches < self::MAX_FETCHES => $this->queueFetch($created),
                    default => $this->hold($created),
                };
            } else {
                $existing++;
            }
        }

        return ['added' => $added, 'existing' => $existing];
    }

    /** Send a listed document to be fetched, counting it against the limit. */
    private function queueFetch(Document $document): void
    {
        FetchDocument::queueFor($document);
        $this->fetches++;
    }

    /** Leave a listed document beyond the limit unfetched, saying why. */
    private function hold(Document $document): void
    {
        $document->update(['status_message' => __('Not fetched: beyond the :max newest documents of one update list.', ['max' => self::MAX_FETCHES])]);
        $this->held++;
    }
}
