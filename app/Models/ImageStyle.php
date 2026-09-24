<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 時間帯ごとの絵柄 (UI "Style by time of day"), set on 編成 › 画像: one row per
 * band of the day, each running from its local start to the next band's.
 */
class ImageStyle extends Model
{
    /** The bands, earliest first: name, local start and style (empty in code). */
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

        // Saved rows override the defaults.
        foreach (static::query()->get() as $row) {
            $bands[$row->band] = ['name' => $row->name, 'starts_at' => $row->starts_at, 'style' => $row->style];
        }

        uasort($bands, fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));

        return $bands;
    }

    /** The band a local time falls in: the last started by then, else the last of the day (past midnight). */
    public static function bandFor(string $time): string
    {
        $bands = self::bands();
        $found = array_key_last($bands);

        // Keep the latest band that has started.
        foreach ($bands as $band => $settings) {
            if (strcmp($settings['starts_at'], $time) <= 0) {
                $found = $band;
            }
        }

        return (string) $found;
    }
}
