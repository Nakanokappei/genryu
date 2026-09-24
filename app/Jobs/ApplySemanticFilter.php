<?php

namespace App\Jobs;

use App\Actions\MeasureLikeness;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 意味フィルタ (UI: "Semantic filter"): in the background, measure a
 * document's likeness (App\Actions\MeasureLikeness) between the title
 * filter and the screening, for every source. At or above the threshold
 * set on 文書 it goes on to the screening; below it goes no further and
 * shows as 対象外 with its likeness. Should the filter itself fail (the
 * embedding call), the document goes on to the screening all the same:
 * a filter that cannot measure must not hold back what nobody looked at.
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

    public function handle(MeasureLikeness $measure): void
    {
        $document = $this->document;

        try {
            $likeness = $measure($document);
        } catch (Throwable $exception) {
            $document->update(['likeness' => null, 'likeness_detail' => ['error' => ErrorMessage::of($exception, 500)]]);
            ScreenDocument::queueFor($document);

            return;
        }

        if ($likeness >= EditorialPolicy::likenessThreshold() && $document->excluded_by === null && $document->screening_id === null) {
            ScreenDocument::queueFor($document);
        }
    }
}
