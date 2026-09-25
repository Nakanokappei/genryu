<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The settings of スケジュール (UI "Schedule"), one row: 平日の公開本数
 * (articles_per_weekday, also how many articles are written a day), 対象期間
 * (period_days), 公開時刻 (publication_times) and 合格点 (pass_mark).
 */
class ScheduleSetting extends Model
{
    public const DEFAULTS = ['articles_per_weekday' => 5, 'period_days' => 7, 'publication_times' => ['07:00', '09:00', '12:00', '15:00', '18:00'], 'pass_mark' => 80];

    protected $fillable = ['articles_per_weekday', 'period_days', 'publication_times', 'pass_mark'];

    protected function casts(): array
    {
        return ['articles_per_weekday' => 'integer', 'period_days' => 'integer', 'publication_times' => 'array', 'pass_mark' => 'integer'];
    }

    /** The saved settings, or the defaults. */
    public static function current(): self
    {
        return static::query()->first() ?? new self(self::DEFAULTS);
    }

    /**
     * A weekday's slots: the earliest articles_per_weekday of the times.
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
