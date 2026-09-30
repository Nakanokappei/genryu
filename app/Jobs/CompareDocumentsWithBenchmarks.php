<?php

namespace App\Jobs;

use App\Actions\CompareWithBenchmarks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** ベンチマーク類似度 (UI "Benchmark similarity") of every embedded document, again, without calling the model. */
class CompareDocumentsWithBenchmarks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** Compare every embedded document. */
    public function handle(CompareWithBenchmarks $compare): void
    {
        $compare->again();
    }
}
