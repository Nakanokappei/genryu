<?php

namespace App\Jobs;

use App\Actions\FetchUpdates;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 更新リストを取得 (UI "Fetch updates") for one source, run by the schedule; a failure stays in the failed jobs. */
class FetchSourceUpdates implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Source $source) {}

    /** Read the source's update list. */
    public function handle(FetchUpdates $fetch): void
    {
        $fetch($this->source);
    }
}
