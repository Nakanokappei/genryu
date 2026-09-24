<?php

namespace App\Actions;

use App\Crawl\Crawler;
use App\Crawl\Feed;
use App\Crawl\HtmlList;
use App\Crawl\JsonList;
use App\Crawl\PublishedDate;
use App\Jobs\ApplySemanticFilter;
use App\Jobs\FetchDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Source;
use RuntimeException;

/**
 * 更新リストを取得 (UI: "Fetch updates", stage 2.1 of docs/HANDOVER.md).
 *
 * Deterministic. A source with a JSON list configuration is read from
 * that JSON file; one with an HTML list configuration from its HTML
 * list, page by page. Otherwise a feed is looked for in a fixed order:
 * the source URL itself is RSS / Atom; the HTML page advertises one with
 * <link rel="alternate">; a well-known path answers with one. Without
 * any of these there is nothing to read and the user is told so. How
 * each kind of list is read is in App\Crawl; this action chooses one and
 * keeps what it lists.
 */
class FetchUpdates
{
    /** The last HTML page of the source read during a fetch, for its favicon. */
    private ?string $pageHtml = null;

    public function __construct(private FetchFavicon $favicon) {}

    /**
     * @return array{feed_url: ?string, pages: int, added: int, existing: int}
     */
    public function __invoke(Source $source): array
    {
        $this->pageHtml = null;
        $json = $source->json_config ?? [];
        $config = $source->list_config ?? [];

        $result = match (true) {
            ($json['url'] ?? '') !== '' => $this->fromJsonList($source, $json),
            ($config['item'] ?? '') !== '' => $this->fromHtmlList($source, $config),
            default => $this->fromFeed($source),
        };

        // The icon is taken, or checked for a change with If-Modified-Since, while we are at the site anyway.
        ($this->favicon)($source, $this->pageHtml, checkAgain: true);

        return $result;
    }

    /**
     * Read the JSON file the site draws its list from, newest first, up
     * to max_items entries.
     *
     * @param  array<string, mixed>  $config
     * @return array{feed_url: null, pages: int, added: int, existing: int}
     */
    private function fromJsonList(Source $source, array $config): array
    {
        $entries = JsonList::entries(Crawler::get((string) $config['url'])->body(), $config, $source->url);

        if ($entries === []) {
            throw new RuntimeException(__('The JSON list settings matched nothing.'));
        }

        $counts = $this->store($source, array_slice($entries, 0, max(1, (int) ($config['max_items'] ?? JsonList::DEFAULT_MAX_ITEMS))));
        $source->update(['feed_url' => null, 'fetched_at' => now()]);

        return ['feed_url' => null, 'pages' => 1, ...$counts];
    }

    /**
     * @return array{feed_url: string, pages: int, added: int, existing: int}
     */
    private function fromFeed(Source $source): array
    {
        $this->pageHtml = Crawler::get($source->url)->body();
        [$feedUrl, $body] = Feed::discover($source->url, $this->pageHtml)
            ?? throw new RuntimeException(__('No RSS or Atom feed found. Fill in the HTML list settings to read this page.'));

        $counts = $this->store($source, Feed::entries($body));
        $source->update(['feed_url' => $feedUrl, 'fetched_at' => now()]);

        return ['feed_url' => $feedUrl, 'pages' => 1, ...$counts];
    }

    /**
     * Read the HTML list page by page. The next page is read only while a
     * next link exists, the page just read listed something new, and the
     * page budget is not used up: a routine fetch stops at the first page
     * that is already known, a first fetch stops at max_pages.
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

        if ($pages === 1 && $added + $existing === 0) {
            throw new RuntimeException(__('The HTML list settings matched nothing on this page.'));
        }

        $source->update(['feed_url' => null, 'fetched_at' => now()]);

        return ['feed_url' => null, 'pages' => $pages, 'added' => $added, 'existing' => $existing];
    }

    /**
     * Keep the documents listed; each new one is fetched in the background
     * (stage 2.2) without anyone asking, unless its title has an exclude
     * keyword of the editorial policy: it is then listed as 対象外 with the
     * keyword, and not fetched. A document another source has listed
     * already is not listed again (arXiv: a paper announced in two of the
     * categories we read, one source each), and counts as existing. A
     * source with a link to the full text
     * (全文へのリンク) whose feed gives a summary is not fetched either: the
     * summary is the document until it is adopted (format feed), and it
     * goes straight to the semantic filter, which passes it on to the
     * screening or leaves it out.
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

        foreach ($entries as $entry) {
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

            if ($created->wasRecentlyCreated) {
                $added++;

                match (true) {
                    $created->excluded_by !== null => null,
                    $created->format === 'feed' => ApplySemanticFilter::queueFor($created),
                    default => FetchDocument::queueFor($created),
                };
            } else {
                $existing++;
            }
        }

        return ['added' => $added, 'existing' => $existing];
    }
}
