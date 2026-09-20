<?php

namespace App\Acquisition\Agent\Tools;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Tools\ArrayResult;
use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\Storage\StorageTool;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;

/**
 * store_source_profile_candidate for the Agent. The candidate is validated
 * against the v1 schema and always lands as PENDING_APPROVAL for the run's
 * own source; there is no path from here to ACTIVE (AT-02, AT-13).
 */
final class StoreProfileCandidateTool implements Tool
{
    public function __construct(private StorageTool $storage) {}

    public function name(): string
    {
        return 'store_source_profile_candidate';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        $data = RequestValidation::validate($payload, [
            'profile' => ['required', 'array'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return new AgentNormalizePayload(['profile' => $payload['profile'], 'change_reason' => $data['change_reason'] ?? null]);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof AgentNormalizePayload) {
            throw new ToolError(ErrorCode::InvalidInput, 'store_source_profile_candidate expects a payload.');
        }

        $payload = $request->toArray();
        $run = AcquisitionRun::query()->findOrFail($context->runId);
        /** @var array<string, mixed> $profile */
        $profile = $payload['profile'];

        $candidate = $this->storage->storeSourceProfileCandidate($run->source, $profile, $run, $payload['change_reason'] ?? null);

        return new ArrayResult([
            'profile_id' => $candidate->id,
            'source_key' => $run->source->key,
            'version' => $candidate->version,
            'status' => $candidate->status->value,
            'message' => 'Stored as PENDING_APPROVAL. A human must approve it before Monitoring can use it.',
        ]);
    }
}
