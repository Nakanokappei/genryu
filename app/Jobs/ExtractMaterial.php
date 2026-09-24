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
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 素材情報を抽出 (UI: "Extract material"): have the agent write the parts of
 * an article from the pinned revision of a document, per the structuring layer.
 */
class ExtractMaterial implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public Material $material) {}

    /** Create or reset the document's material (抽出中), pin revision, prompt and model, and queue it. */
    public static function queueFor(Document $document): Material
    {
        $material = Material::query()->updateOrCreate(
            ['document_id' => $document->id],
            [
                'status' => 'extracting',
                'status_message' => null,
                'document_revision_id' => $document->recordRevision()?->id,
                'prompt_id' => Prompt::forLayer('structuring')->id,
                'model' => EditorialPolicy::modelFor('structuring'),
            ],
        );

        self::dispatch($material);

        return $material;
    }

    /** Extract, check, repair once, and record the parts or the failure on the material. */
    public function handle(ProposeMaterial $propose, ValidateMaterial $validate): void
    {
        $material = $this->material;
        $document = $material->document;
        $usage = [];

        try {
            // Only a fetched document with a pinned revision.
            if ($document->status !== 'fetched' || $material->revision === null) {
                throw new RuntimeException(__('The document has not been fetched yet.'));
            }

            // The gate: a rejected document is refused.
            if ($document->isRejected()) {
                throw new RuntimeException(__('The screening rejected this document.'));
            }

            $policy = Prompt::textOf($material->prompt, 'structuring');

            $model = (string) $material->model;

            $result = $propose($policy, $model, $material->revision->markdown);
            $usage[] = $result['usage'];
            $errors = $validate($result['json']);

            // Repair once with the errors in hand.
            if ($errors !== []) {
                $result = $propose($policy, $model, $material->revision->markdown, $errors);
                $usage[] = $result['usage'];
                $errors = $validate($result['json']);
            }

            // Still failing: keep the failed checks and fail.
            if ($errors !== []) {
                $material->update(['failed_checks' => $errors]);

                throw new RuntimeException(__('The material did not pass the checks: :errors', ['errors' => implode(' / ', array_slice($errors, 0, 5))]));
            }

            // Figures come from the Markdown, not the model; none leaves the part out.
            $figures = CollectFigures::from($material->revision->markdown);

            $material->update([
                'parts' => $figures === [] ? $result['json'] : [...$result['json'], 'figures' => $figures],
                'failed_checks' => [],
                'status' => 'extracted',
                'status_message' => __('Extracted by :model.', ['model' => $model]),
                ...Usage::sum($usage),
                'estimated_total_cost' => Usage::costOfCalls($model, $usage),
            ]);
        } catch (Throwable $exception) {
            $material->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception), ...Usage::sum($usage)]);
        }
    }
}
