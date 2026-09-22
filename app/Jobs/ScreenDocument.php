<?php

namespace App\Jobs;

use App\Actions\ProposeDecision;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Screening;
use App\Models\ScreeningPrompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * スクリーニング (UI: "Screening", the Editorial Screening Gate): in the
 * background, have the agent read a fetched document's Markdown with the
 * content filtering prompt and decide 採用 / 不採用 / 要確認. The run is
 * kept as a screening row with the prompt version, the model, the
 * tokens and the estimated cost, and becomes the document's latest
 * screening; a rejected document is not sent on to the detailed
 * analysis (App\Jobs\ExtractMaterial refuses it). The outcome lands on
 * the screening (status 判定中 / 判定済み / 失敗) so the screens can show it.
 */
class ScreenDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public Screening $screening) {}

    /**
     * Queue the screening of a document with the prompt as it is now and
     * the model chosen for the content filtering (or one named here, to
     * review a document with a higher model): the run appears at once as
     * 判定中 and is the document's latest screening.
     */
    public static function queueFor(Document $document, ?string $model = null): Screening
    {
        $screening = Screening::query()->create([
            'document_id' => $document->id,
            'screening_prompt_id' => ScreeningPrompt::current('content_filtering', EditorialPolicy::bodyFor('content_filtering'))->id,
            'model' => $model ?? EditorialPolicy::modelFor('content_filtering'),
            'status' => 'screening',
        ]);
        $document->update(['screening_id' => $screening->id]);

        self::dispatch($screening);

        return $screening;
    }

    public function handle(ProposeDecision $propose): void
    {
        $screening = $this->screening;
        $document = $screening->document;

        try {
            if ($document->excluded_by !== null) {
                throw new RuntimeException(__('The document is excluded by the title filter.'));
            }

            if ($document->status !== 'fetched' || (string) $document->markdown === '') {
                throw new RuntimeException(__('The document has not been fetched yet.'));
            }

            $prompt = $screening->prompt->text;

            if (trim($prompt) === '') {
                throw new RuntimeException(__('The content filtering prompt is empty.'));
            }

            $result = $propose($prompt, $screening->model, (string) $document->markdown);

            $screening->update([
                ...$result,
                ...self::estimatedCost($screening->model, $result),
                'status' => 'screened',
                'status_message' => null,
            ]);
        } catch (Throwable $exception) {
            $screening->update(['status' => 'failed', 'status_message' => mb_substr(mb_scrub($exception->getMessage(), 'UTF-8'), 0, 1000)]);
        }
    }

    /**
     * What the call cost, from the prices per million tokens configured
     * for the model: cached input tokens at the cached price, the rest of
     * the input at the input price, the output at the output price. Null
     * when the model's prices are not known.
     *
     * @param  array{input_tokens: ?int, cached_tokens: ?int, output_tokens: ?int}  $usage
     * @return array{estimated_input_cost: ?float, estimated_output_cost: ?float, estimated_total_cost: ?float}
     */
    public static function estimatedCost(string $model, array $usage): array
    {
        $prices = config("services.openai.prices.{$model}");

        if (! is_array($prices) || ! is_numeric($prices['input'] ?? null) || ! is_numeric($prices['output'] ?? null) || $usage['input_tokens'] === null || $usage['output_tokens'] === null) {
            return ['estimated_input_cost' => null, 'estimated_output_cost' => null, 'estimated_total_cost' => null];
        }

        $cached = $usage['cached_tokens'] ?? 0;
        $cachedPrice = is_numeric($prices['cached'] ?? null) ? (float) $prices['cached'] : (float) $prices['input'];
        $input = (($usage['input_tokens'] - $cached) * (float) $prices['input'] + $cached * $cachedPrice) / 1_000_000;
        $output = $usage['output_tokens'] * (float) $prices['output'] / 1_000_000;

        return ['estimated_input_cost' => $input, 'estimated_output_cost' => $output, 'estimated_total_cost' => $input + $output];
    }
}
