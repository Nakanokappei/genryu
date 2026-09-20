<?php

namespace App\Acquisition\Agent;

/**
 * What an orchestrator reports back. Mirrors the worker's output document;
 * the run engine derives the run status from it.
 */
final readonly class DiscoveryOutcome
{
    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    /**
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public string $status,
        public ?int $candidateProfileId,
        public int $toolCalls,
        public ?string $model,
        public array $usage = [],
        public ?float $costUsd = null,
        public ?string $summary = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: in_array($data['status'] ?? null, [self::COMPLETED, self::FAILED, self::BUDGET_EXHAUSTED], true) ? $data['status'] : self::FAILED,
            candidateProfileId: isset($data['candidate_profile_id']) ? (int) $data['candidate_profile_id'] : null,
            toolCalls: (int) ($data['tool_calls'] ?? 0),
            model: isset($data['model']) ? (string) $data['model'] : null,
            usage: is_array($data['usage'] ?? null) ? $data['usage'] : [],
            costUsd: isset($data['cost_usd']) ? (float) $data['cost_usd'] : null,
            summary: isset($data['summary']) ? mb_substr((string) $data['summary'], 0, 4000) : null,
            error: isset($data['error']) ? mb_substr((string) $data['error'], 0, 2000) : null,
        );
    }

    public static function failed(string $error): self
    {
        return new self(self::FAILED, null, 0, null, [], null, null, $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'candidate_profile_id' => $this->candidateProfileId,
            'tool_calls' => $this->toolCalls,
            'model' => $this->model,
            'usage' => $this->usage,
            'cost_usd' => $this->costUsd,
            'summary' => $this->summary,
            'error' => $this->error,
        ];
    }
}
