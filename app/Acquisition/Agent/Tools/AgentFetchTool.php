<?php

namespace App\Acquisition\Agent\Tools;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Tools\ArrayResult;
use App\Acquisition\Tools\Http\FetchRequest;
use App\Acquisition\Tools\Http\PolicyFetcher;
use App\Acquisition\Tools\Storage\StorageTool;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;

/**
 * fetch_url as the Agent sees it: crawl policy applied, the RAW artifact
 * stored immediately, and the body replaced by a blob reference. The Agent
 * never receives bytes; it passes the reference to the parse tools.
 */
final class AgentFetchTool implements Tool
{
    public function __construct(
        private PolicyFetcher $fetcher,
        private StorageTool $storage,
    ) {}

    public function name(): string
    {
        return 'fetch_url';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        return FetchRequest::fromArray($payload);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof FetchRequest) {
            throw new ToolError(ErrorCode::InvalidInput, 'fetch_url expects a FetchRequest.');
        }

        $run = AcquisitionRun::query()->findOrFail($context->runId);
        /** @var array<string, mixed> $budget */
        $budget = $run->budget ?? [];
        $requestsPerMinute = (int) ($budget['requests_per_minute'] ?? config('acquisition.discovery.requests_per_minute'));

        try {
            $result = $this->fetcher->fetch($request, $requestsPerMinute, $context);
        } catch (ToolError $error) {
            $this->storage->recordFetchObservation($run, $run->source, $request->url, null, null, $error);

            throw $error;
        }

        $raw = $result->notModified ? null : $this->storage->storeRawArtifact($result);
        $this->storage->recordFetchObservation($run, $run->source, $request->url, $result, $raw);

        return new ArrayResult([
            ...$result->toArray(),
            'raw_artifact' => $raw === null ? null : [
                'id' => $raw->id,
                'blob_uri' => $raw->blob_uri,
                'sha256' => $raw->sha256,
                'bytes' => $raw->bytes,
                'media_type' => $raw->media_type,
            ],
        ]);
    }
}
