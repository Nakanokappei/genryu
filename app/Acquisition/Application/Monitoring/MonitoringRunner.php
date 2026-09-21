<?php

namespace App\Acquisition\Application\Monitoring;

use App\Acquisition\Application\DocumentPipeline;
use App\Acquisition\Application\IngestOutcome;
use App\Acquisition\Application\RunCounters;
use App\Acquisition\Application\RunLifecycle;
use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Enums\SourceStatus;
use App\Acquisition\Domain\Identity\UrlNormalizer;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Document;
use App\Acquisition\Domain\Models\FetchObservation;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use App\Acquisition\Health\HealthEvaluator;
use App\Acquisition\Health\HealthVerdict;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Http\FetchRequest;
use App\Acquisition\Tools\Http\PolicyFetcher;
use App\Acquisition\Tools\Storage\StorageTool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\Xml\ParseXmlTool;
use InvalidArgumentException;
use Throwable;

/**
 * Monitoring Mode (plan §5.2). Walks only the ACTIVE profile's entrypoints
 * with conditional GETs, matches entries against document_patterns, fetches
 * only documents that are new or possibly changed, and hands each to the
 * DocumentPipeline. It never explores; re-discovery is a separate run.
 */
final class MonitoringRunner
{
    public function __construct(
        private RunLifecycle $lifecycle,
        private PolicyFetcher $fetcher,
        private DocumentPipeline $pipeline,
        private StorageTool $storage,
        private ParseXmlTool $xml,
        private ParseHtmlTool $html,
        private HealthEvaluator $health,
        private BlobStore $blobs,
    ) {}

    /**
     * Create the PENDING run, or explain why monitoring must not start.
     *
     * @throws MonitoringSkipped
     */
    public function prepare(Source $source): AcquisitionRun
    {
        if ($source->status !== SourceStatus::Active) {
            throw new MonitoringSkipped('disabled', "Source {$source->key} is {$source->status->value}.");
        }

        if ($this->lifecycle->isBlocked($source)) {
            throw new MonitoringSkipped('circuit_breaker', "Source {$source->key} is blocked until {$source->next_run_not_before?->toIso8601ZuluString()} after {$source->consecutive_failures} consecutive failures.");
        }

        // AT-02: only an ACTIVE profile may drive monitoring.
        $profile = $source->activeProfile()->first()
            ?? throw new MonitoringSkipped('no_active_profile', "Source {$source->key} has no ACTIVE profile; approve a candidate first.");

        return $this->lifecycle->start($source, RunMode::Monitoring, $profile);
    }

    public function execute(AcquisitionRun $run): HealthVerdict
    {
        $this->lifecycle->begin($run);
        $source = $run->source;
        $counters = new RunCounters;

        try {
            $profile = $run->profile;

            if ($profile === null || $profile->status !== ProfileStatus::Active) {
                throw new MonitoringSkipped('no_active_profile', 'The run profile is no longer ACTIVE.');
            }

            $context = ToolContext::forRun($run->id);
            $patterns = new DocumentPatterns($profile->profile_json['document_patterns'] ?? []);
            $readings = [];
            $candidates = [];

            foreach ($this->entrypoints($profile) as $entrypoint) {
                $this->readEntrypoint($run, $source, $profile, $entrypoint['url'], $entrypoint['type'], $context, $counters, $patterns, $readings, $candidates, 0);
            }

            $this->fetchDocuments($run, $source, $profile, $context, $counters, $candidates);

            $verdict = $this->health->evaluate($run, $source, $profile, $readings, $counters, $patterns->warnings);
        } catch (Throwable $exception) {
            $this->lifecycle->fail($run, $exception, $counters);

            throw $exception;
        }

        $this->lifecycle->finish($run, $counters, $verdict->drift);

        return $verdict;
    }

    /**
     * Profile entrypoints, highest priority first.
     *
     * @return list<array{url: string, type: string, priority: int}>
     */
    private function entrypoints(SourceProfile $profile): array
    {
        /** @var list<array{url: string, type: string, priority: int}> $entrypoints */
        $entrypoints = $profile->profile_json['entrypoints'] ?? [];
        usort($entrypoints, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $entrypoints;
    }

    /**
     * Fetch one entrypoint conditionally, ingest it as a document of its
     * own, and collect the document candidates it lists.
     *
     * @param  list<EntrypointReading>  $readings
     * @param  array<string, array{url: string, guid: string|null, published: string|null, document_type: string, identity_from: string}>  $candidates
     */
    private function readEntrypoint(AcquisitionRun $run, Source $source, SourceProfile $profile, string $url, string $type, ToolContext $context, RunCounters $counters, DocumentPatterns $patterns, array &$readings, array &$candidates, int $depth): void
    {
        $reading = new EntrypointReading($url, $type);
        $readings[] = $reading;

        try {
            $result = $this->fetcher->fetch($this->request($url, $profile, $this->conditionalHeaders($source, $url)), $this->requestsPerMinute($profile), $context);
        } catch (ToolError $error) {
            $reading->error = $error->errorCode->value;
            $this->storage->recordFetchObservation($run, $source, $url, null, null, $error);

            return;
        }

        $reading->status = $result->status;

        if ($result->notModified) {
            // Unchanged entrypoint: its documents may still have changed, so
            // the candidate list is replayed from the stored RAW (no network).
            $this->storage->recordFetchObservation($run, $source, $url, $result);
            $this->replayStoredEntrypoint($run, $source, $profile, $url, $type, $context, $counters, $patterns, $readings, $candidates, $reading, $depth);

            return;
        }

        $reading->mediaType = $result->declaredMediaType ?? $result->detectedMediaType;
        $expectedXml = in_array($type, ['rss', 'atom', 'sitemap', 'sitemap_index'], true);

        // The entrypoint is a primary source too: RAW kept, revisioned, normalized.
        try {
            $this->pipeline->ingest($run, $source, $profile, $result, null, $expectedXml ? 'feed' : 'other');
        } catch (ToolError $error) {
            $reading->error = $error->errorCode->value;

            return;
        }

        $this->collectCandidates($run, $source, $profile, $result->body, $result->finalUrl, $type, $context, $counters, $patterns, $readings, $candidates, $reading, $depth);
    }

    /**
     * Re-read the last stored RAW of an entrypoint that answered 304, so its
     * documents are still checked without fetching the entrypoint again.
     *
     * @param  list<EntrypointReading>  $readings
     * @param  array<string, array{url: string, guid: string|null, published: string|null, document_type: string, identity_from: string}>  $candidates
     */
    private function replayStoredEntrypoint(AcquisitionRun $run, Source $source, SourceProfile $profile, string $url, string $type, ToolContext $context, RunCounters $counters, DocumentPatterns $patterns, array &$readings, array &$candidates, EntrypointReading $reading, int $depth): void
    {
        $revision = $source->documents()
            ->where('stable_key', 'url:'.UrlNormalizer::normalize($url))
            ->first()
            ?->revisions()
            ->orderByDesc('revision_no')
            ->first();

        if ($revision === null) {
            return;
        }

        $raw = $revision->rawArtifact;
        $reading->mediaType = $raw->media_type;
        /** @var array<string, mixed> $metadata */
        $metadata = $raw->metadata ?? [];

        $this->collectCandidates($run, $source, $profile, $this->blobs->get($raw->blob_uri), (string) ($metadata['final_url'] ?? $url), $type, $context, $counters, $patterns, $readings, $candidates, $reading, $depth);
    }

    /**
     * Extract document candidates from an entrypoint body, XML or HTML.
     *
     * @param  list<EntrypointReading>  $readings
     * @param  array<string, array{url: string, guid: string|null, published: string|null, document_type: string, identity_from: string}>  $candidates
     */
    private function collectCandidates(AcquisitionRun $run, Source $source, SourceProfile $profile, string $body, string $finalUrl, string $type, ToolContext $context, RunCounters $counters, DocumentPatterns $patterns, array &$readings, array &$candidates, EntrypointReading $reading, int $depth): void
    {
        $expectedXml = in_array($type, ['rss', 'atom', 'sitemap', 'sitemap_index'], true);
        $isXml = in_array($reading->mediaType, ['text/xml', 'application/xml', 'application/rss+xml', 'application/atom+xml'], true);
        $reading->contentTypeChanged = $expectedXml !== $isXml;

        if ($isXml) {
            $this->readXmlEntries($run, $source, $profile, $body, $finalUrl, $context, $counters, $patterns, $readings, $candidates, $reading, $depth);
        } else {
            $this->readHtmlLinks($body, $finalUrl, $patterns, $candidates, $reading);
        }
    }

    /**
     * @param  list<EntrypointReading>  $readings
     * @param  array<string, array{url: string, guid: string|null, published: string|null, document_type: string, identity_from: string}>  $candidates
     */
    private function readXmlEntries(AcquisitionRun $run, Source $source, SourceProfile $profile, string $body, string $finalUrl, ToolContext $context, RunCounters $counters, DocumentPatterns $patterns, array &$readings, array &$candidates, EntrypointReading $reading, int $depth): void
    {
        try {
            $parsed = $this->xml->parse($body, $finalUrl);
        } catch (ToolError $error) {
            $reading->error = $error->errorCode->value;

            return;
        }

        $reading->entryCount = count($parsed->entries) + count($parsed->children);

        foreach ($parsed->entries as $entry) {
            if ($entry['url'] !== null) {
                $this->addCandidate($candidates, $patterns, $entry['url'], $entry['id'], $entry['published'] ?? $entry['updated']);
            }
        }

        // A sitemap index lists more sitemaps; read them as entrypoints too, once.
        if ($parsed->kind === 'sitemap_index' && $depth === 0) {
            foreach (array_slice($parsed->children, 0, (int) config('acquisition.monitoring.max_sitemap_children')) as $child) {
                $this->readEntrypoint($run, $source, $profile, $child['url'], 'sitemap', $context, $counters, $patterns, $readings, $candidates, 1);
            }
        }
    }

    /**
     * @param  array<string, array{url: string, guid: string|null, published: string|null, document_type: string, identity_from: string}>  $candidates
     */
    private function readHtmlLinks(string $body, string $finalUrl, DocumentPatterns $patterns, array &$candidates, EntrypointReading $reading): void
    {
        try {
            $parsed = $this->html->parse($body, $finalUrl);
        } catch (ToolError $error) {
            $reading->error = $error->errorCode->value;

            return;
        }

        $matched = 0;

        foreach ($parsed->links as $link) {
            if ($this->addCandidate($candidates, $patterns, $link['url'], null)) {
                $matched++;
            }
        }

        $reading->entryCount = $matched;
    }

    /**
     * @param  array<string, array{url: string, guid: string|null, published: string|null, document_type: string, identity_from: string}>  $candidates
     */
    private function addCandidate(array &$candidates, DocumentPatterns $patterns, string $url, ?string $guid, ?string $published = null): bool
    {
        // Patterns and fetches work on the normalized URL, so tracking noise
        // in a feed link neither defeats a pattern nor splits an identity.
        try {
            $normalized = UrlNormalizer::normalize($url);
        } catch (InvalidArgumentException) {
            return false;
        }

        $match = $patterns->match($normalized);

        if ($match === null) {
            return false;
        }

        $candidates[$normalized] ??= ['url' => $normalized, 'guid' => $guid, 'published' => $published, 'document_type' => $match['document_type'], 'identity_from' => $match['identity_from']];

        return true;
    }

    /**
     * Fetch candidate documents up to max_urls_per_run: known documents
     * conditionally, unknown ones plainly. Each goes through the pipeline.
     *
     * @param  array<string, array{url: string, guid: string|null, published: string|null, document_type: string, identity_from: string}>  $candidates
     */
    private function fetchDocuments(AcquisitionRun $run, Source $source, SourceProfile $profile, ToolContext $context, RunCounters $counters, array $candidates): void
    {
        $limit = (int) ($profile->profile_json['crawl_policy']['max_urls_per_run'] ?? 500);
        $skipped = max(0, count($candidates) - $limit);

        if ($skipped > 0) {
            $counters->increment(RunCounters::SKIPPED, $skipped);
        }

        // Unknown documents first, so a large sitemap is backfilled a slice per
        // run instead of the same known pages being re-checked forever.
        $knownDocuments = [];

        foreach ($candidates as $normalized => $candidate) {
            $knownDocuments[$normalized] = $this->knownDocument($source, $candidate['identity_from'] === 'feed_guid' ? $candidate['guid'] : null, $normalized);
        }

        uksort($candidates, static fn (string $a, string $b): int => ($knownDocuments[$a] === null ? 0 : 1) <=> ($knownDocuments[$b] === null ? 0 : 1));

        foreach (array_slice($candidates, 0, $limit, true) as $normalized => $candidate) {
            $feedGuid = $candidate['identity_from'] === 'feed_guid' ? $candidate['guid'] : null;
            $known = $knownDocuments[$normalized];
            $counters->increment(RunCounters::FETCHED);

            try {
                $result = $this->fetcher->fetch(
                    $this->request($candidate['url'], $profile, $known !== null ? $this->conditionalHeaders($source, $candidate['url']) : null),
                    $this->requestsPerMinute($profile),
                    $context,
                );

                if ($result->notModified) {
                    $this->storage->recordFetchObservation($run, $source, $candidate['url'], $result);
                    $known?->update(['last_seen_at' => $result->retrievedAt]);
                    $counters->increment(RunCounters::UNCHANGED);

                    continue;
                }

                $outcome = $this->pipeline->ingest($run, $source, $profile, $result, $feedGuid, $candidate['document_type'], $candidate['published']);
            } catch (ToolError $error) {
                if ($error->errorCode->value !== 'PARSE_FAILED' && $error->errorCode->value !== 'QUALITY_FAILED') {
                    // Pipeline errors already recorded their observation; fetch errors have not.
                    $this->storage->recordFetchObservation($run, $source, $candidate['url'], null, null, $error);
                }

                $counters->increment(RunCounters::FAILED);

                continue;
            }

            $counters->increment(match ($outcome->change) {
                IngestOutcome::NEW => RunCounters::NEW,
                IngestOutcome::REVISED => RunCounters::REVISED,
                default => RunCounters::UNCHANGED,
            });

            if (! $outcome->qualityPassed) {
                $counters->increment(RunCounters::QUALITY_FAILED);
            }
        }
    }

    private function knownDocument(Source $source, ?string $feedGuid, string $normalizedUrl): ?Document
    {
        $keys = array_filter(['url:'.$normalizedUrl, $feedGuid !== null ? 'guid:'.$feedGuid : null]);

        return $source->documents()->whereIn('stable_key', $keys)->first();
    }

    /**
     * ETag / Last-Modified of the last successful fetch of this URL.
     *
     * @return array{etag: string|null, last_modified: string|null}|null
     */
    private function conditionalHeaders(Source $source, string $url): ?array
    {
        $last = FetchObservation::query()
            ->where('source_id', $source->id)
            ->where('url', $url)
            ->where('status', 200)
            ->orderByDesc('retrieved_at')
            ->first();

        if ($last === null) {
            return null;
        }

        $headers = $last->headers ?? [];
        $etag = $headers['etag'] ?? null;
        $lastModified = $headers['last-modified'] ?? null;

        return $etag === null && $lastModified === null ? null : ['etag' => $etag, 'last_modified' => $lastModified];
    }

    /**
     * @param  array{etag: string|null, last_modified: string|null}|null  $conditional
     */
    private function request(string $url, SourceProfile $profile, ?array $conditional): FetchRequest
    {
        /** @var array<string, int> $policy */
        $policy = $profile->profile_json['crawl_policy'] ?? [];
        /** @var list<string> $hosts */
        $hosts = $profile->profile_json['allowed_hosts'] ?? [];

        return new FetchRequest(
            url: $url,
            allowedHosts: array_map('strtolower', $hosts),
            ifNoneMatch: $conditional['etag'] ?? null,
            ifModifiedSince: $conditional['last_modified'] ?? null,
            timeoutSeconds: (int) ($policy['timeout_seconds'] ?? config('acquisition.fetch.timeout_seconds')),
            maxBodyBytes: (int) ($policy['max_body_bytes'] ?? config('acquisition.fetch.max_body_bytes')),
            maxRedirects: (int) config('acquisition.fetch.max_redirects'),
            maxAttempts: (int) config('acquisition.fetch.max_attempts'),
        );
    }

    private function requestsPerMinute(SourceProfile $profile): int
    {
        return (int) ($profile->profile_json['crawl_policy']['requests_per_minute'] ?? config('acquisition.discovery.requests_per_minute'));
    }
}
