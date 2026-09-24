<?php

namespace App\Jobs;

use App\Actions\CollectFigures;
use App\Actions\ProposeMaterial;
use App\Actions\ValidateMaterial;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\Prompt;
use App\OpenAi\Usage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 素材情報を抽出 (UI: "Extract material", stage 2.3 of docs/HANDOVER.md):
 * in the background, have the agent read the revision of the document's
 * Markdown pinned when the job was queued as the structuring layer says:
 * what changed, and that change through the editorial lenses, every
 * statement saying whether it comes from the primary source, from
 * general knowledge or from inference. The dossier is checked for
 * holding together (App\Actions\ValidateMaterial) and a miss is repaired
 * once with the errors in hand. The JSON, the revision,
 * the prompt version, the model, the usage and the report of the checks
 * are kept on the material; a rejected document is refused.
 * The outcome lands on the material (status 抽出中 / 抽出済み / 失敗) so
 * the screens can show it.
 */
class ExtractMaterial implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public Material $material) {}

    /**
     * Queue the extraction for a document: its material row appears at
     * once as 抽出中, whether it is new or being extracted again, pinned
     * to the document's Markdown as it is now and the prompt as it is now.
     */
    public static function queueFor(Document $document): Material
    {
        $material = Material::query()->updateOrCreate(
            ['document_id' => $document->id],
            [
                'status' => 'extracting',
                'status_message' => null,
                'document_revision_id' => $document->recordRevision()?->id,
                'prompt_id' => Prompt::current('structuring', EditorialPolicy::bodyFor('structuring'))->id,
                'model' => EditorialPolicy::modelFor('structuring'),
            ],
        );

        self::dispatch($material);

        return $material;
    }

    public function handle(ProposeMaterial $propose, ValidateMaterial $validate): void
    {
        $material = $this->material;
        $document = $material->document;
        $usage = [];

        try {
            if ($document->status !== 'fetched' || $material->revision === null) {
                throw new RuntimeException(__('The document has not been fetched yet.'));
            }

            // The gate: a rejected document (by a person, else by the screening) does not reach the detailed analysis.
            if ($document->isRejected()) {
                throw new RuntimeException(__('The screening rejected this document.'));
            }

            $policy = $material->prompt !== null ? $material->prompt->text : '';

            if (trim($policy) === '') {
                throw new RuntimeException(__('The structuring layer of the editorial policy is empty.'));
            }

            $model = (string) $material->model;

            // The answer, checked; a dossier that does not hold together is repaired once with the errors in hand.
            $result = $propose($policy, $model, $material->revision->markdown);
            $usage[] = $result['usage'];
            $errors = $validate($result['json']);

            if ($errors !== []) {
                $result = $propose($policy, $model, $material->revision->markdown, $errors);
                $usage[] = $result['usage'];
                $errors = $validate($result['json']);
            }

            if ($errors !== []) {
                $material->update(['validation' => $errors]);

                throw new RuntimeException(__('The material did not pass the checks: :errors', ['errors' => implode(' / ', array_slice($errors, 0, 5))]));
            }

            // The figures of the source are gathered from its Markdown, not asked of the model; a document without any leaves the part out.
            $figures = CollectFigures::from($material->revision->markdown);

            $material->update([
                'data' => $figures === [] ? $result['json'] : [...$result['json'], 'figures' => $figures],
                'validation' => [],
                'status' => 'extracted',
                'status_message' => __('Extracted by :model.', ['model' => $model]),
                ...Usage::sum($usage),
                'estimated_total_cost' => self::cost($model, $usage),
            ]);
        } catch (Throwable $exception) {
            $material->update(['status' => 'failed', 'status_message' => mb_substr(mb_scrub($exception->getMessage(), 'UTF-8'), 0, 1000), ...Usage::sum($usage)]);
        }
    }

    /**
     * @param  list<array<string, ?int>>  $usage
     */
    private static function cost(string $model, array $usage): ?float
    {
        $total = null;

        foreach ($usage as $call) {
            $cost = Usage::estimatedCost($model, ['input_tokens' => $call['input_tokens'], 'cached_tokens' => $call['cached_tokens'], 'output_tokens' => $call['output_tokens']])['estimated_total_cost'];

            if ($cost === null) {
                return null;
            }

            $total = ($total ?? 0.0) + $cost;
        }

        return $total;
    }
}
