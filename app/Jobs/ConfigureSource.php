<?php

namespace App\Jobs;

use App\Actions\FetchFavicon;
use App\Actions\FetchUpdates;
use App\Actions\ProposeListSettings;
use App\Crawl\Crawler;
use App\Crawl\Feed;
use App\Crawl\HtmlList;
use App\Crawl\JsonList;
use App\Models\Source;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Configure a new source: find its feed or JSON list, else have the agent
 * propose HTML list settings verified on the page; then read the update list.
 */
class ConfigureSource implements ShouldQueue
{
    use Queueable;

    /** A proposal must find at least this many entries on the page to be believed. */
    public const MINIMUM_ENTRIES = 3;

    public const DEFAULT_MAX_PAGES = 3;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public Source $source) {}

    /** Find how the source lists its updates, save it, and read the list; a failure lands on the source. */
    public function handle(FetchUpdates $fetch, ProposeListSettings $propose, FetchFavicon $favicon): void
    {
        $source = $this->source;

        try {
            $html = Crawler::get($source->url)->body();
            $favicon($source, $html);
            $feed = $source->list_method === 'html' ? null : Feed::discover($source->url, $html);

            // A feed, else a JSON list, else HTML list settings from the agent.
            if ($feed !== null) {
                // Feed entries the page also links to, shown so a wrong feed stands out.
                $overlap = count(array_filter(Feed::entries($feed[1]), fn (array $entry): bool => str_contains($html, (string) parse_url($entry['url'], PHP_URL_PATH))));

                $source->update(['feed_url' => $feed[0], 'html_list_settings' => null, 'json_list_settings' => null, 'status' => 'configured', 'status_message' => __('Feed found: :feed (:overlap entries also linked on the page)', ['feed' => $feed[0], 'overlap' => $overlap])]);
            } elseif (($json = JsonList::discover($html, $source->url)) !== null) {
                $source->update(['feed_url' => null, 'html_list_settings' => null, 'json_list_settings' => $json['config'], 'status' => 'configured', 'status_message' => __('JSON list found: :url (:count entries)', ['url' => $json['config']['url'], 'count' => count($json['entries'])])]);
            } else {
                $proposal = $propose($html, $source->url);
                [$proposal, $entries] = self::verify($html, $proposal, $source->url);

                // Too few entries: the proposal is not believed.
                if (count($entries) < self::MINIMUM_ENTRIES) {
                    throw new RuntimeException(__('The proposed settings matched :count entries on the page; at least :minimum are needed.', ['count' => count($entries), 'minimum' => self::MINIMUM_ENTRIES]));
                }

                // The first titles, to show whether the right list was chosen.
                $sample = implode(' / ', array_map(fn (array $entry): string => mb_substr($entry['title'], 0, 40), array_slice($entries, 0, 3)));

                $source->update([
                    'feed_url' => null,
                    'json_list_settings' => null,
                    'html_list_settings' => [...$proposal, 'max_pages' => self::DEFAULT_MAX_PAGES],
                    'status' => 'configured',
                    'status_message' => __('HTML list settings proposed by the agent and verified on the page (:count entries: :sample).', ['count' => count($entries), 'sample' => $sample]),
                ]);
            }

            $fetch($source->refresh());
        } catch (Throwable $exception) {
            $source->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);
        }
    }

    /**
     * Apply the proposal to the page, falling back to generic title and date
     * selectors; return the first settings that work, else those that found most.
     *
     * @param  array<string, string>  $proposal
     * @return array{0: array<string, string>, 1: list<array{title: string, url: string, published_at: ?string}>}
     */
    private static function verify(string $html, array $proposal, string $url): array
    {
        $best = [$proposal, []];

        // Each title selector with each date selector, the proposal's first.
        foreach (array_unique([$proposal['title'], 'h1, h2, h3, h4', 'a[href]']) as $title) {
            foreach (array_unique([$proposal['date'], 'time', '']) as $date) {
                $settings = [...$proposal, 'title' => $title, 'date' => $date];
                $entries = HtmlList::preview($html, $settings, $url);
                $dated = count(array_filter($entries, fn (array $entry): bool => $entry['published_at'] !== null));

                // Enough entries, and dated when a date selector is set.
                if (count($entries) >= self::MINIMUM_ENTRIES && ($date === '' || $dated > 0)) {
                    return [$settings, $entries];
                }

                // Otherwise remember the one that found most.
                if (count($entries) > count($best[1])) {
                    $best = [$settings, $entries];
                }
            }
        }

        return $best;
    }
}
