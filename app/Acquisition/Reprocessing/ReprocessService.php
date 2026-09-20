<?php

namespace App\Acquisition\Reprocessing;

use App\Acquisition\Application\RunCounters;
use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\DocumentRevision;
use App\Acquisition\Domain\Models\NormalizedArtifact;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Normalize\SourceContext;
use App\Acquisition\Tools\Parsers\ParserRegistry;
use App\Acquisition\Tools\Parsers\ParserResolver;
use App\Acquisition\Tools\Storage\StorageTool;
use App\Acquisition\Tools\ToolError;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-parses stored RAW artifacts with a given parser version and stores the
 * result as additional NORMALIZED artifacts (plan §11, AT-07). Reads only
 * the BlobStore and the database: nothing here can reach the network.
 */
final class ReprocessService
{
    public function __construct(
        private BlobStore $blobs,
        private ParserResolver $parsers,
        private NormalizeDocumentTool $normalizer,
        private StorageTool $storage,
    ) {}

    /**
     * Count what an execution would do, writing nothing.
     */
    public function plan(ReprocessRequest $request): ReprocessPlan
    {
        $this->assertParserKnown($request->parserId);

        $revisions = $this->revisions($request)->with('rawArtifact')->get();
        $applicable = $revisions->filter(fn (DocumentRevision $revision): bool => $this->parsers->appliesTo($request->parserId, $revision->rawArtifact->media_type));
        $existing = $applicable->filter(fn (DocumentRevision $revision): bool => $this->alreadyProcessed($revision, $request->parserId));

        return new ReprocessPlan(
            revisions: $applicable->count(),
            alreadyProcessed: $existing->count(),
            toProcess: $applicable->count() - $existing->count(),
            rawBytes: (int) $applicable->reject(fn (DocumentRevision $revision): bool => $this->alreadyProcessed($revision, $request->parserId))
                ->sum(fn (DocumentRevision $revision): int => $revision->rawArtifact->bytes),
        );
    }

    /**
     * Execute against a RUNNING run. Failures are recorded per document and
     * reflected in the counters; the run keeps going.
     */
    public function execute(ReprocessRequest $request, AcquisitionRun $run, RunCounters $counters): ReprocessReport
    {
        $this->assertParserKnown($request->parserId);

        $report = new ReprocessReport;
        $source = $run->source;
        $profile = $source->activeProfile()->first();

        /** @var DocumentRevision $revision */
        foreach ($this->revisions($request)->with(['rawArtifact', 'document'])->lazyById() as $revision) {
            $raw = $revision->rawArtifact;

            if (! $this->parsers->appliesTo($request->parserId, $raw->media_type)) {
                continue;
            }

            if ($this->alreadyProcessed($revision, $request->parserId)) {
                $report->skippedExisting++;
                $counters->increment(RunCounters::SKIPPED);

                continue;
            }

            try {
                /** @var array<string, mixed> $metadata */
                $metadata = $raw->metadata ?? [];
                $finalUrl = (string) ($metadata['final_url'] ?? $revision->document->canonical_url ?? '');
                $requestedUrl = (string) ($metadata['requested_url'] ?? $finalUrl);
                /** @var array<string, mixed> $expectations */
                $expectations = $profile?->profile_json['quality_expectations'] ?? [];

                $parsed = $this->parsers->parse($request->parserId, $this->blobs->get($raw->blob_uri), $finalUrl);
                $normalized = $this->normalizer->normalize($parsed, new SourceContext(
                    sourceKey: $source->key,
                    requestedUrl: $requestedUrl,
                    finalUrl: $finalUrl,
                    retrievedAt: isset($metadata['retrieved_at']) ? CarbonImmutable::parse((string) $metadata['retrieved_at'])->utc() : CarbonImmutable::instance($raw->created_at ?? $revision->detected_at)->utc(),
                    mediaType: $raw->media_type,
                    rawSha256: $raw->sha256,
                    rawBlobUri: $raw->blob_uri,
                    documentType: $revision->document->document_type,
                    requiredFields: array_values($expectations['required_fields'] ?? ['canonical_url', 'title']),
                    minimumTextCharacters: (int) ($expectations['minimum_text_characters'] ?? 200),
                ));

                $this->storage->storeNormalizedArtifact($revision, $normalized, $run);

                $report->reprocessed++;
                $counters->increment(RunCounters::REPROCESSED);

                if (! $normalized->quality['passed']) {
                    $counters->increment(RunCounters::QUALITY_FAILED);
                }
            } catch (Throwable $exception) {
                $this->recordFailure($run, $source, $revision, $exception, $report, $counters);
            }
        }

        return $report;
    }

    /**
     * @return Builder<DocumentRevision>
     */
    private function revisions(ReprocessRequest $request): Builder
    {
        $query = DocumentRevision::query()
            ->whereHas('document', function (Builder $documents) use ($request): void {
                $documents->whereHas('source', fn (Builder $sources) => $sources->where('key', $request->sourceKey));

                if ($request->documentStableKey !== null) {
                    $documents->where('stable_key', $request->documentStableKey);
                }
            });

        if ($request->from !== null) {
            $query->where('detected_at', '>=', $request->from);
        }

        if ($request->to !== null) {
            $query->where('detected_at', '<=', $request->to);
        }

        return $query;
    }

    private function alreadyProcessed(DocumentRevision $revision, string $parserId): bool
    {
        return NormalizedArtifact::query()
            ->where('revision_id', $revision->id)
            ->where('parser_id', $parserId)
            ->where('normalizer_version', ParserRegistry::NORMALIZE_DOCUMENT_V1)
            ->exists();
    }

    private function assertParserKnown(string $parserId): void
    {
        if (! ParserRegistry::has($parserId) || str_starts_with($parserId, 'normalize.')) {
            throw new ToolError(ErrorCode::InvalidInput, "Unknown parser {$parserId}.", ['parser_id' => $parserId, 'known' => ParserRegistry::ids()]);
        }
    }

    private function recordFailure(AcquisitionRun $run, Source $source, DocumentRevision $revision, Throwable $exception, ReprocessReport $report, RunCounters $counters): void
    {
        $code = $exception instanceof ToolError ? $exception->errorCode->value : ErrorCode::Internal->value;
        $report->failures[] = ['revision_id' => $revision->id, 'stable_key' => $revision->document->stable_key, 'code' => $code, 'message' => mb_substr($exception->getMessage(), 0, 500)];
        $counters->increment(RunCounters::FAILED);

        $source->events()->create([
            'run_id' => $run->id,
            'event_type' => 'REPROCESS_DOCUMENT_FAILED',
            'severity' => 'warning',
            'evidence' => ['revision_id' => $revision->id, 'stable_key' => $revision->document->stable_key, 'raw_artifact_id' => $revision->raw_artifact_id, 'code' => $code],
        ]);

        Log::channel('acquisition')->warning('acquisition.reprocess.document_failed', [
            'run_id' => $run->id, 'revision_id' => $revision->id, 'code' => $code, 'exception' => $exception::class,
        ]);
    }
}
