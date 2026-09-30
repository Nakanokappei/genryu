<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Models\Source;

/**
 * ベンチマーク類似度 (UI "Benchmark similarity"): a document's similarity
 * to the nearest document of each ベンチマーク, the highest kept on the
 * document. Recorded only; it decides nothing yet.
 */
class CompareWithBenchmarks
{
    /** Compares an embedded document with the benchmarks and stores the result; null when there is nothing to compare. */
    public function __invoke(Document $document): ?float
    {
        $embedding = DocumentEmbedding::query()->where('document_id', $document->id)->first();

        // Not embedded yet.
        if ($embedding === null) {
            return null;
        }

        return $this->keep($document, $embedding->vector, $embedding->model);
    }

    /**
     * Compares every embedded document of the sources again, without calling the model.
     *
     * @return int documents compared
     */
    public function again(): int
    {
        $compared = 0;

        DocumentEmbedding::query()->whereHas('document', fn ($document) => $document->fromSources())->with('document')->chunkById(100, function ($embeddings) use (&$compared): void {
            foreach ($embeddings as $embedding) {
                $compared += $this->keep($embedding->document, $embedding->vector, $embedding->model) !== null ? 1 : 0;
            }
        });

        return $compared;
    }

    /**
     * Finds the nearest document of each benchmark and stores them, highest first.
     *
     * @param  list<float>  $vector
     */
    private function keep(Document $document, array $vector, string $model): ?float
    {
        $nearest = [];

        // The nearest document per benchmark.
        foreach ($this->benchmarkDocuments($model) as $candidate) {
            $similarity = round(MeasureLikeness::dot($vector, $candidate['vector']), 4);

            if (! isset($nearest[$candidate['source_id']]) || $similarity > $nearest[$candidate['source_id']]['similarity']) {
                $nearest[$candidate['source_id']] = ['source_id' => $candidate['source_id'], 'benchmark' => $candidate['benchmark'], 'similarity' => $similarity, 'document_id' => $candidate['document_id'], 'title' => $candidate['title'], 'url' => $candidate['url']];
            }
        }

        $nearest = array_values($nearest);
        usort($nearest, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
        $similarity = $nearest[0]['similarity'] ?? null;
        $document->update(['benchmark_similarity' => $similarity, 'benchmark_detail' => $nearest === [] ? null : ['model' => $model, 'measured_at' => now()->toIso8601String(), 'benchmarks' => $nearest]]);

        return $similarity;
    }

    /**
     * The benchmarks' documents embedded with the model, not excluded, within
     * Source::BENCHMARK_WINDOW_DAYS; loaded once per instance.
     *
     * @return list<array{document_id: int, source_id: int, benchmark: string, title: string, url: string, vector: list<float>}>
     */
    private function benchmarkDocuments(string $model): array
    {
        return once(fn (): array => array_values(DocumentEmbedding::query()
            ->join('documents', 'documents.id', '=', 'document_embeddings.document_id')
            ->join('sources', 'sources.id', '=', 'documents.source_id')
            ->where('sources.is_benchmark', true)
            ->where('document_embeddings.model', $model)
            ->whereNull('documents.excluded_by')
            ->whereRaw('coalesce(documents.published_at, documents.created_at) >= ?', [now()->subDays(Source::BENCHMARK_WINDOW_DAYS)])
            ->get(['document_embeddings.vector', 'documents.id as document_id', 'documents.source_id', 'sources.name as benchmark', 'documents.title', 'documents.url'])
            ->map(fn (DocumentEmbedding $row): array => [
                'document_id' => (int) $row->getAttribute('document_id'),
                'source_id' => (int) $row->getAttribute('source_id'),
                'benchmark' => (string) $row->getAttribute('benchmark'),
                'title' => (string) $row->getAttribute('title'),
                'url' => (string) $row->getAttribute('url'),
                'vector' => array_map(floatval(...), $row->vector),
            ])->all()));
    }
}
