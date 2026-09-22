<?php

namespace App\Jobs;

use App\Actions\ProposeMaterial;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\UpdateEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 素材情報を抽出 (UI: "Extract material", stage 2.3 of docs/HANDOVER.md):
 * in the background, have the agent structure the fetched document of an
 * update entry per the structuring layer of the editorial policy, check
 * that every item the policy lists is there, and keep the JSON as the
 * entry's material. The outcome lands on the material (status 抽出中 /
 * 抽出済み / 失敗) so the screens can show it.
 */
class ExtractMaterial implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public Material $material) {}

    /**
     * Queue the extraction for an update entry: its material row appears
     * at once as 抽出中, whether it is new or being extracted again.
     */
    public static function queueFor(UpdateEntry $entry): Material
    {
        $material = Material::query()->updateOrCreate(
            ['update_entry_id' => $entry->id],
            ['status' => 'extracting', 'status_message' => null],
        );

        self::dispatch($material);

        return $material;
    }

    public function handle(ProposeMaterial $propose): void
    {
        $material = $this->material;
        $entry = $material->updateEntry;

        try {
            if ($entry->status !== 'fetched' || (string) $entry->markdown === '') {
                throw new RuntimeException(__('The document has not been fetched yet.'));
            }

            $policy = EditorialPolicy::bodyFor('structuring');

            if (trim($policy) === '') {
                throw new RuntimeException(__('The structuring layer of the editorial policy is empty.'));
            }

            $data = $propose($policy, (string) $entry->markdown, $entry->url);
            $missing = array_values(array_diff(EditorialPolicy::items($policy), array_keys($data)));

            if ($missing !== []) {
                throw new RuntimeException(__('The agent left out these items: :items', ['items' => implode(', ', $missing)]));
            }

            $material->update(['data' => $data, 'status' => 'extracted', 'status_message' => __('Extracted by :model.', ['model' => (string) config('services.openai.model')])]);
        } catch (Throwable $exception) {
            $material->update(['status' => 'failed', 'status_message' => mb_substr($exception->getMessage(), 0, 1000)]);
        }
    }
}
