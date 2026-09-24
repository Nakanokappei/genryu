<?php

namespace App\Actions;

use App\Models\Screening;
use App\OpenAi\Responses;
use RuntimeException;

/**
 * The agent of スクリーニング (UI "Screening"): decides adopt / reject /
 * review for a document under the content filtering prompt, with a reason
 * class, evidence and reason. App\Jobs\ScreenDocument keeps the run.
 */
class ProposeDecision
{
    /** Characters of Markdown sent. */
    private const MAX_MARKDOWN_CHARS = 120000;

    /** Instruction for the second pass, sent after the cached prompt. */
    public const SECOND_PASS = 'This is the second pass on a document the first pass sent to REVIEW. REVIEW is not available this time: weigh the evidence in the document and decide ADOPT or REJECT.';

    /**
     * Sends the request and returns the decision with its usage.
     *
     * @param  int  $pass  1 for the first pass, 2 for the second, which may only adopt or reject
     * @return array{decision: string, reason_class: string, evidence: string, reason: string, input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}
     */
    public function __invoke(string $prompt, string $model, string $markdown, int $pass = 1): array
    {
        ['json' => $decision, 'usage' => $usage] = Responses::send(self::request($prompt, $model, $markdown, $pass), 180, 'The agent did not return a decision.');

        // No valid decision in the answer.
        if (! in_array(strtolower((string) ($decision['decision'] ?? '')), Screening::DECISIONS, true)) {
            throw new RuntimeException(__('The agent did not return a decision.'));
        }

        return [
            'decision' => strtolower((string) $decision['decision']),
            'reason_class' => (string) ($decision['primary_reason'] ?? ''),
            'evidence' => (string) ($decision['evidence'] ?? ''),
            'reason' => (string) ($decision['reason'] ?? ''),
            ...$usage,
        ];
    }

    /**
     * The request: cached prompt, SECOND_PASS on pass 2, then the document; no REVIEW on pass 2.
     *
     * @return array<string, mixed>
     */
    public static function request(string $prompt, string $model, string $markdown, int $pass = 1): array
    {
        return Responses::request($model, [
            Responses::policy($prompt),
            ...($pass >= 2 ? [['role' => 'developer', 'content' => self::SECOND_PASS]] : []),
            [
                'role' => 'user',
                'content' => mb_substr($markdown, 0, self::MAX_MARKDOWN_CHARS),
            ],
        ], 'screening_decision', [
            'type' => 'object',
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => $pass >= 2 ? ['ADOPT', 'REJECT'] : ['ADOPT', 'REJECT', 'REVIEW']],
                'primary_reason' => ['type' => 'string', 'enum' => array_keys(Screening::REASONS)],
                'evidence' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['decision', 'primary_reason', 'evidence', 'reason'],
            'additionalProperties' => false,
        ]);
    }
}
