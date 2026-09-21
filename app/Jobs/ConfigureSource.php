<?php

namespace App\Jobs;

use App\Actions\FetchUpdates;
use App\Actions\ProposeListSettings;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Configure a new source in the background: find its feed or the JSON
 * list it draws its entries from, both deterministically, or have the
 * agent propose HTML list settings and verify them on the page, then
 * read the update list for the first time.
 * The outcome lands on the source (status 設定中 / 設定済み / 失敗) rather
 * than in the queue's failed-jobs table, so the screen can show it.
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

    public function handle(FetchUpdates $fetch, ProposeListSettings $propose): void
    {
        $source = $this->source;

        try {
            $html = $fetch->page($source->url);
            $feed = $source->read_as_html ? null : $fetch->discoverFeed($source->url, $html);

            if ($feed !== null) {
                // How many feed entries the page itself links to: a probed feed
                // that shares nothing with the page is probably another list.
                $overlap = count(array_filter(FetchUpdates::previewFeed($feed[1]), fn (array $entry): bool => str_contains($html, (string) parse_url($entry['url'], PHP_URL_PATH))));

                $source->update(['feed_url' => $feed[0], 'list_config' => null, 'json_config' => null, 'status' => 'ready', 'status_message' => __('Feed found: :feed (:overlap entries also linked on the page)', ['feed' => $feed[0], 'overlap' => $overlap])]);
            } elseif (($json = $fetch->discoverJsonList($html, $source->url)) !== null) {
                // 三菱電機: the page holds no entries, a script draws them from a JSON file.
                $source->update(['feed_url' => null, 'list_config' => null, 'json_config' => $json['config'], 'status' => 'ready', 'status_message' => __('JSON list found: :url (:count entries)', ['url' => $json['config']['url'], 'count' => count($json['entries'])])]);
            } else {
                $proposal = $propose($html, $source->url);
                [$proposal, $entries] = self::verify($html, $proposal, $source->url);

                if (count($entries) < self::MINIMUM_ENTRIES) {
                    throw new RuntimeException(__('The proposed settings matched :count entries on the page; at least :minimum are needed.', ['count' => count($entries), 'minimum' => self::MINIMUM_ENTRIES]));
                }

                // The first titles let the operator see at a glance whether the right list was chosen.
                $sample = implode(' / ', array_map(fn (array $entry): string => mb_substr($entry['title'], 0, 40), array_slice($entries, 0, 3)));

                $source->update([
                    'feed_url' => null,
                    'json_config' => null,
                    'list_config' => [...$proposal, 'max_pages' => self::DEFAULT_MAX_PAGES],
                    'status' => 'ready',
                    'status_message' => __('HTML list settings proposed by the agent and verified on the page (:count entries: :sample).', ['count' => count($entries), 'sample' => $sample]),
                ]);
            }

            $fetch($source->refresh());
        } catch (Throwable $exception) {
            $source->update(['status' => 'failed', 'status_message' => mb_substr($exception->getMessage(), 0, 1000)]);
        }
    }

    /**
     * Apply the proposal to the page. The agent usually gets the item right
     * and the title wrong (CNRS: an anchor wrapping a heading, proposed as
     * a heading holding an anchor), so when the proposed title or date
     * selector finds nothing, generic ones are tried in a fixed order and
     * the settings that actually worked are what gets saved.
     *
     * @param  array<string, string>  $proposal
     * @return array{0: array<string, string>, 1: list<array{title: string, url: string, published_at: ?string}>}
     */
    private static function verify(string $html, array $proposal, string $url): array
    {
        $best = [$proposal, []];

        foreach (array_unique([$proposal['title'], 'h1, h2, h3, h4', 'a[href]']) as $title) {
            foreach (array_unique([$proposal['date'], 'time', '']) as $date) {
                $settings = [...$proposal, 'title' => $title, 'date' => $date];
                $entries = FetchUpdates::previewList($html, $settings, $url);
                $dated = count(array_filter($entries, fn (array $entry): bool => $entry['published_at'] !== null));

                if (count($entries) >= self::MINIMUM_ENTRIES && ($date === '' || $dated > 0)) {
                    return [$settings, $entries];
                }

                if (count($entries) > count($best[1])) {
                    $best = [$settings, $entries];
                }
            }
        }

        return $best;
    }
}
