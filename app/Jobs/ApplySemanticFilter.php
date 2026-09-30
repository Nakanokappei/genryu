<?php

namespace App\Jobs;

use App\Actions\CompareWithBenchmarks;
use App\Actions\MeasureLikeness;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 意味フィルタ (UI: "Semantic filter"): measure a document's likeness and
 * send it on to the screening when it reaches the threshold.
 */
class ApplySemanticFilter implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Document $document) {}

    public static function queueFor(Document $document): void
    {
        self::dispatch($document);
    }

    /** Measure the likeness and queue the screening for a document that passes. */
    public function handle(MeasureLikeness $measure): void
    {
        $document = $this->document;

        // A benchmark's document is only compared with, never filtered.
        if ($document->source->is_benchmark) {
            return;
        }

        // A filter that cannot measure lets the document through.
        try {
            $likeness = $measure($document);
        } catch (Throwable $exception) {
            $document->update(['likeness' => null, 'likeness_detail' => ['error' => ErrorMessage::of($exception, 500)]]);
            if ($document->excluded_by === null && $document->latest_screening_id === null) {
                ScreenDocument::queueFor($document);
            }

            return;
        }

        // ベンチマーク類似度, recorded only: a failure is noted and decides nothing.
        try {
            app(CompareWithBenchmarks::class)($document);
        } catch (Throwable $exception) {
            $document->update(['benchmark_similarity' => null, 'benchmark_detail' => ['error' => ErrorMessage::of($exception, 500)]]);
        }

        // At or above the threshold, not excluded and not yet screened: screen it.
        if ($likeness >= EditorialPolicy::likenessThreshold() && $document->excluded_by === null && $document->latest_screening_id === null) {
            ScreenDocument::queueFor($document);
        }
    }
}
