<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The settings of スケジュール (UI: "Schedule"): how many articles go out
 * on a weekday (平日の公開本数), how many days after its primary source was
 * published an article may still be scheduled (対象期間), and the local
 * times of day the articles of a day go out at (公開時刻), earliest first.
 * One row; the defaults stand until the screen saves it.
 */
class ScheduleSetting extends Model
{
    public const DEFAULTS = ['articles_per_weekday' => 5, 'period_days' => 7, 'publication_times' => ['07:00', '09:00', '12:00', '15:00', '18:00']];

    protected $fillable = ['articles_per_weekday', 'period_days', 'publication_times'];

    protected function casts(): array
    {
        return ['articles_per_weekday' => 'integer', 'period_days' => 'integer', 'publication_times' => 'array'];
    }

    /** The settings as saved, or the defaults until they are. */
    public static function current(): self
    {
        return static::query()->first() ?? new self(self::DEFAULTS);
    }

    /**
     * The times a weekday's articles go out at: the first
     * articles_per_weekday of the times, in order.
     *
     * @return list<string>
     */
    public function slots(): array
    {
        $times = (array) $this->publication_times;
        sort($times);

        return array_slice($times, 0, $this->articles_per_weekday);
    }
}
