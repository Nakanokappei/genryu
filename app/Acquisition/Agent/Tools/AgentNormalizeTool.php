<?php

namespace App\Acquisition\Agent\Tools;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\RawArtifact;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Normalize\NormalizeRequest;
use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * normalize_document for the Agent: it supplies the parsed artifact and the
 * RAW blob it came from; the source context (URLs, time, hash, quality
 * expectations of the active profile) is reconstructed here from the RAW
 * artifact's provenance so the Agent cannot misstate it.
 */
final class AgentNormalizeTool implements Tool
{
    public function __construct(private NormalizeDocumentTool $normalizer) {}

    public function name(): string
    {
        return 'normalize_document';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        RequestValidation::validate($payload, [
            'parsed' => ['required', 'array'],
            'raw_blob_uri' => ['required', 'string', 'starts_with:acquisition://raw/'],
            'document_type' => ['nullable', 'string', 'max:32'],
            'feed_guid' => ['nullable', 'string'],
            'feed_published_at' => ['nullable', 'date'],
        ]);

        // The run is not known until run(); carry the payload as-is.
        return new AgentNormalizePayload($payload);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof AgentNormalizePayload) {
            throw new ToolError(ErrorCode::InvalidInput, 'normalize_document expects an AgentNormalizePayload.');
        }

        $payload = $request->toArray();
        $run = AcquisitionRun::query()->findOrFail($context->runId);
        $source = $run->source;
        $raw = RawArtifact::query()->where('blob_uri', $payload['raw_blob_uri'])->first();

        if ($raw === null) {
            throw new ToolError(ErrorCode::InvalidInput, 'Unknown RAW artifact.', ['raw_blob_uri' => $payload['raw_blob_uri']]);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $raw->metadata ?? [];
        /** @var array<string, mixed> $expectations */
        $expectations = $source->activeProfile()->first()?->profile_json['quality_expectations'] ?? [];
        $finalUrl = (string) ($metadata['final_url'] ?? '');

        $inner = $this->normalizer->parseRequest([
            'parsed' => $payload['parsed'],
            'source_context' => [
                'source_key' => $source->key,
                'requested_url' => $metadata['requested_url'] ?? $finalUrl,
                'final_url' => $finalUrl,
                'retrieved_at' => $metadata['retrieved_at'] ?? CarbonImmutable::instance($raw->created_at ?? CarbonImmutable::now())->toIso8601ZuluString(),
                'media_type' => $raw->media_type,
                'raw_sha256' => $raw->sha256,
                'raw_blob_uri' => $raw->blob_uri,
                'feed_guid' => $payload['feed_guid'] ?? null,
                'feed_published_at' => $payload['feed_published_at'] ?? null,
                'document_type' => $payload['document_type'] ?? null,
                'quality_expectations' => [
                    'required_fields' => array_values($expectations['required_fields'] ?? ['canonical_url', 'title']),
                    'minimum_text_characters' => (int) ($expectations['minimum_text_characters'] ?? 200),
                ],
            ],
        ]);

        if (! $inner instanceof NormalizeRequest) {
            throw new ToolError(ErrorCode::Internal, 'Normalizer returned an unexpected request type.');
        }

        return $this->normalizer->run($inner, $context);
    }
}
