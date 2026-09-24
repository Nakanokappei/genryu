<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 時間帯ごとの絵柄 (UI: "Style by time of day"), set on 編成 › 画像: the day is
 * cut into bands, each starting at a local time and running to the next,
 * the last one round midnight to the first; an article's top image is
 * drawn in the style of the band its slot falls in, so the five articles
 * of a day look like the hours they go out at rather than like one
 * another. Decided 2026-09-23; on 2026-09-25 the pop art moved from
 * お昼休み前 to お昼休み中, お昼休み前 became machines with no people, and the
 * picture book was dropped. One row per band; the defaults stand until
 * the screen saves them.
 */
class ImageStyle extends Model
{
    /** The bands of the day, earliest first: the name shown, the local time it starts at, and the style the image model is given. */
    public const DEFAULTS = [
        'before_work' => ['name' => '始業前', 'starts_at' => '06:00', 'style' => ''],
        'before_lunch' => ['name' => 'お昼休み前', 'starts_at' => '09:00', 'style' => ''],
        'lunch_break' => ['name' => 'お昼休み中', 'starts_at' => '12:00', 'style' => ''],
        'afternoon' => ['name' => '午後', 'starts_at' => '13:00', 'style' => ''],
        'before_closing' => ['name' => '終業前', 'starts_at' => '15:00', 'style' => ''],
        'after_work' => ['name' => '終業後', 'starts_at' => '17:00', 'style' => ''],
        'late_night' => ['name' => '深夜帯', 'starts_at' => '21:00', 'style' => ''],
    ];

    protected $fillable = ['band', 'name', 'starts_at', 'style'];

    /**
     * Every band, earliest start first, as saved or by default.
     *
     * @return array<string, array{name: string, starts_at: string, style: string}>
     */
    public static function bands(): array
    {
        $bands = self::DEFAULTS;

        foreach (static::query()->get() as $row) {
            $bands[$row->band] = ['name' => $row->name, 'starts_at' => $row->starts_at, 'style' => $row->style];
        }

        uasort($bands, fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));

        return $bands;
    }

    /**
     * The band a local time of day falls in: the last one to have started
     * by then, or — before the first has started — the last of the day,
     * which runs on past midnight.
     */
    public static function bandFor(string $time): string
    {
        $bands = self::bands();
        $found = array_key_last($bands);

        foreach ($bands as $band => $settings) {
            if (strcmp($settings['starts_at'], $time) <= 0) {
                $found = $band;
            }
        }

        return (string) $found;
    }
}
