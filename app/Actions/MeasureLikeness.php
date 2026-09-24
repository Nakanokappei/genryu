<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Models\EditorialPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * らしさ (UI: "Likeness"), the measure of the 意味フィルタ: how much nearer
 * a document is to what is like this media than to what is unlike it.
 * The document's title and text are embedded once (App\Actions\Embed,
 * kept as its DocumentEmbedding) and compared with every definition of
 * the semantic filter and every example a person marked; the likeness is
 * the similarity of the nearest "like" minus that of the nearest
 * "unlike". A document is never its own example. The absolute
 * similarities mean little (unrelated texts score 0.1 to 0.3), which is
 * why the two sides are set against each other rather than against a
 * fixed bar.
 */
class MeasureLikeness
{
    /** How much of a document is embedded: its title and the start of its text. */
    public const MAX_CHARS = 8000;

    public function __construct(private Embed $embed) {}

    /**
     * Measure one document, embedding it first when it has no embedding
     * for the model the filter runs on, and keep the likeness on it.
     */
    public function __invoke(Document $document): float
    {
        $filter = EditorialPolicy::semanticFilter();
        $embedding = $document->embedding;

        if ($embedding === null || $embedding->model !== $filter['model']) {
            ['vectors' => [$vector], 'tokens' => $tokens] = ($this->embed)([self::text($document)], $filter['model']);
            $embedding = DocumentEmbedding::query()->updateOrCreate(['document_id' => $document->id], ['model' => $filter['model'], 'vector' => $vector, 'tokens' => $tokens]);
        }

        return $this->keep($document, $embedding->vector, $filter);
    }

    /**
     * Measure again every document that has an embedding for the model,
     * after the definitions, the examples or the threshold changed: no
     * document is embedded again, only the definitions are (when new).
     *
     * @return array{measured: int, below: int}
     */
    public function again(): array
    {
        $filter = EditorialPolicy::semanticFilter();
        $measured = 0;
        $below = 0;

        DocumentEmbedding::query()->where('model', $filter['model'])->with('document')->chunkById(100, function ($embeddings) use ($filter, &$measured, &$below): void {
            foreach ($embeddings as $embedding) {
                $likeness = $this->keep($embedding->document, $embedding->vector, $filter);
                $measured++;
                $below += $likeness < $filter['threshold'] ? 1 : 0;
            }
        });

        return ['measured' => $measured, 'below' => $below];
    }

    /**
     * What a document is judged on: its title and text, the Markdown's own
     * title line and date line left out, cut at MAX_CHARS.
     */
    public static function text(Document $document): string
    {
        $body = (string) preg_replace(['/^#\s.*$/m', '/^\d{4}-\d{2}-\d{2}(T\S*)?$/m'], '', (string) $document->markdown);

        return mb_substr(trim($document->title."\n\n".trim($body)), 0, self::MAX_CHARS);
    }

    /**
     * Compare a document's vector with the definitions and the examples,
     * and keep the likeness and what it was measured against.
     *
     * @param  list<float>  $vector
     * @param  array{definitions: list<array{side: string, text: string}>, model: string, threshold: float}  $filter
     */
    private function keep(Document $document, array $vector, array $filter): float
    {
        $nearest = ['like' => null, 'unlike' => null];

        foreach ([...$this->definitions($filter), ...$this->examples($filter['model'], $document->id)] as $candidate) {
            $similarity = self::dot($vector, $candidate['vector']);

            if ($nearest[$candidate['side']] === null || $similarity > $nearest[$candidate['side']]['similarity']) {
                $nearest[$candidate['side']] = ['label' => $candidate['label'], 'similarity' => round($similarity, 4)];
            }
        }

        $likeness = round(($nearest['like']['similarity'] ?? 0.0) - ($nearest['unlike']['similarity'] ?? 0.0), 4);
        $document->update(['likeness' => $likeness, 'likeness_detail' => ['like' => $nearest['like'], 'unlike' => $nearest['unlike'], 'model' => $filter['model']]]);

        return $likeness;
    }

    /**
     * The definitions with their vectors. A definition is embedded once
     * per model and kept in the cache by its text, so editing one line
     * embeds that line only.
     *
     * @param  array{definitions: list<array{side: string, text: string}>, model: string, threshold: float}  $filter
     * @return list<array{side: string, label: string, vector: list<float>}>
     */
    private function definitions(array $filter): array
    {
        $missing = array_values(array_filter($filter['definitions'], fn (array $definition): bool => ! Cache::has(self::cacheKey($filter['model'], $definition['text']))));

        if ($missing !== []) {
            $vectors = ($this->embed)(array_column($missing, 'text'), $filter['model'])['vectors'];

            foreach ($missing as $i => $definition) {
                Cache::forever(self::cacheKey($filter['model'], $definition['text']), $vectors[$i]);
            }
        }

        return array_map(fn (array $definition): array => ['side' => $definition['side'], 'label' => $definition['text'], 'vector' => self::vector(Cache::get(self::cacheKey($filter['model'], $definition['text'])))], $filter['definitions']);
    }

    /**
     * The examples a person marked, with their documents' vectors, the
     * document being measured left out (it would be nearest to itself).
     * An example not embedded yet with this model does not count.
     *
     * @return list<array{side: string, label: string, vector: list<float>}>
     */
    private function examples(string $model, int $documentId): array
    {
        $examples = once(fn (): array => DB::table('semantic_filter_examples')
            ->join('document_embeddings', 'document_embeddings.document_id', '=', 'semantic_filter_examples.document_id')
            ->join('documents', 'documents.id', '=', 'semantic_filter_examples.document_id')
            ->where('document_embeddings.model', $model)
            ->get(['semantic_filter_examples.document_id', 'semantic_filter_examples.side', 'documents.title', 'document_embeddings.vector'])
            ->map(fn (object $example): array => ['document_id' => (int) $example->document_id, 'side' => (string) $example->side, 'label' => 'example:'.$example->document_id.':'.$example->title, 'vector' => self::vector(json_decode((string) $example->vector, true))])
            ->all());

        return array_values(array_map(
            fn (array $example): array => ['side' => $example['side'], 'label' => $example['label'], 'vector' => $example['vector']],
            array_filter($examples, fn (array $example): bool => $example['document_id'] !== $documentId),
        ));
    }

    /**
     * A vector read back from the cache or from JSON, as numbers.
     *
     * @return list<float>
     */
    private static function vector(mixed $value): array
    {
        return array_map(floatval(...), array_values(is_array($value) ? $value : []));
    }

    private static function cacheKey(string $model, string $text): string
    {
        return 'semantic-filter:'.$model.':'.hash('sha256', $text);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $i => $x) {
            $sum += $x * ($b[$i] ?? 0.0);
        }

        return $sum;
    }
}
