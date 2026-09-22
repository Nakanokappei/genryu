<?php

namespace App\Jobs;

use App\Actions\ProposeMaterial;
use App\Actions\ValidateMaterial;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\Prompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 素材情報を抽出 (UI: "Extract material", stage 2.3 of docs/HANDOVER.md):
 * in the background, have the agent build the material of an adopted
 * document from the revision of its Markdown pinned when the job was
 * queued, in two passes (extract the evidence, then finalize the
 * analysis on it), each checked by App\Actions\ValidateMaterial and
 * repaired once with the errors in hand when it fails. The JSON, the
 * revision, the prompt version, the model, the usage and the report of
 * the checks are kept on the material; a rejected document is refused.
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

        try {
            if ($document->status !== 'fetched' || $material->revision === null) {
                throw new RuntimeException(__('The document has not been fetched yet.'));
            }

            // The gate: a rejected document (by a person, else by the screening) does not reach the detailed analysis.
            if ($document->isRejected()) {
                throw new RuntimeException(__('The screening rejected this document.'));
            }

            $prompt = $material->prompt !== null ? $material->prompt->text : '';

            if (trim($prompt) === '') {
                throw new RuntimeException(__('The structuring layer of the editorial policy is empty.'));
            }

            $markdown = $material->revision->markdown;
            $model = (string) $material->model;
            $usage = [];

            // Extract, checked, repaired once.
            [$extract, $errors] = $this->pass($propose, $model, $prompt, 'extract', $markdown, null, fn (array $json): array => $validate->extract($json, $material->revision), $usage);

            if ($errors !== []) {
                throw new RuntimeException(__('The evidence did not pass the checks: :errors', ['errors' => implode(' / ', array_slice($errors, 0, 5))]), previous: null);
            }

            // Finalize on the evidence, checked, repaired once.
            [$finalize, $errors] = $this->pass($propose, $model, $prompt, 'finalize', $markdown, $extract, fn (array $json): array => $validate->finalize($json, $extract), $usage);

            if ($errors !== []) {
                $material->update(['validation' => $errors]);

                throw new RuntimeException(__('The analysis did not pass the checks: :errors', ['errors' => implode(' / ', array_slice($errors, 0, 5))]));
            }

            $material->update([
                'data' => self::merge($extract, $finalize),
                'validation' => [],
                'status' => 'extracted',
                'status_message' => __('Extracted by :model.', ['model' => $model]),
                ...self::summed($usage),
                'estimated_total_cost' => self::cost($model, $usage),
            ]);
        } catch (Throwable $exception) {
            $material->update(['status' => 'failed', 'status_message' => mb_substr(mb_scrub($exception->getMessage(), 'UTF-8'), 0, 1000), ...(isset($usage) ? self::summed($usage) : [])]);
        }
    }

    /**
     * One phase: the agent's answer, checked; when it fails, once more
     * with the errors in hand. Returns the answer and the errors left.
     *
     * @param  array<string, mixed>|null  $evidence
     * @param  callable(array<string, mixed>): list<string>  $check
     * @param  list<array<string, ?int>>  $usage
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function pass(ProposeMaterial $propose, string $model, string $prompt, string $phase, string $markdown, ?array $evidence, callable $check, array &$usage): array
    {
        $result = $propose($prompt, $model, $phase, $markdown, $evidence);
        $usage[] = $result['usage'];
        $errors = $check($result['json']);

        if ($errors === []) {
            return [$result['json'], []];
        }

        $result = $propose($prompt, $model, $phase, $markdown, $evidence, $errors);
        $usage[] = $result['usage'];

        return [$result['json'], $check($result['json'])];
    }

    /**
     * The material: the evidence and the analysis as one JSON, the claims
     * of both phases together.
     *
     * @param  array<string, mixed>  $extract
     * @param  array<string, mixed>  $finalize
     * @return array<string, mixed>
     */
    public static function merge(array $extract, array $finalize): array
    {
        return [
            'schema_version' => ProposeMaterial::SCHEMA_VERSION,
            'source_language' => $extract['source_language'] ?? '',
            'primary_evidence' => $extract['primary_evidence'] ?? [],
            'technology_transition' => $finalize['technology_transition'] ?? [],
            'engineering' => $finalize['engineering'] ?? [],
            'editorial' => $finalize['editorial'] ?? [],
            'claims' => [...($extract['claims'] ?? []), ...($finalize['claims'] ?? [])],
            'provenance' => ['primary_spans' => $extract['primary_spans'] ?? []],
            'quality' => $finalize['quality'] ?? ['warnings' => []],
        ];
    }

    /**
     * @param  list<array<string, ?int>>  $usage
     * @return array<string, ?int>
     */
    private static function summed(array $usage): array
    {
        $sum = fn (string $key): ?int => array_any($usage, fn (array $call): bool => $call[$key] !== null) ? array_sum(array_map(fn (array $call): int => (int) $call[$key], $usage)) : null;

        return ['input_tokens' => $sum('input_tokens'), 'cached_tokens' => $sum('cached_tokens'), 'cache_write_tokens' => $sum('cache_write_tokens'), 'output_tokens' => $sum('output_tokens'), 'latency_ms' => $sum('latency_ms')];
    }

    /**
     * @param  list<array<string, ?int>>  $usage
     */
    private static function cost(string $model, array $usage): ?float
    {
        $total = null;

        foreach ($usage as $call) {
            $cost = ScreenDocument::estimatedCost($model, ['input_tokens' => $call['input_tokens'], 'cached_tokens' => $call['cached_tokens'], 'output_tokens' => $call['output_tokens']])['estimated_total_cost'];

            if ($cost === null) {
                return null;
            }

            $total = ($total ?? 0.0) + $cost;
        }

        return $total;
    }
}
