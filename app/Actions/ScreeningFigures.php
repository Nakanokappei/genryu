<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\Screening;

/**
 * The figures of the screenings shown on 文書, per prompt version: how
 * many were screened and how they were decided, how much of the input
 * came from the cache or was written to it, and what a screening and an
 * adoption cost on average; the reason classes counted apart.
 */
class ScreeningFigures
{
    /**
     * @return array{versions: list<array<string, mixed>>, reasons: list<array{reason_class: string, decision: string, meaning: string, count: int, share: float}>}
     */
    public function __invoke(): array
    {
        // Aggregate rows, not screenings: read as plain rows.
        $versions = Screening::query()->where('status', 'screened')
            ->join('prompts', 'prompts.id', '=', 'screenings.prompt_id')
            ->groupBy('prompts.version')->orderByDesc('prompts.version')
            ->selectRaw('prompts.version, count(*) as screened, sum(case when decision = ? then 1 else 0 end) as adopted, sum(case when decision = ? then 1 else 0 end) as rejected, sum(case when decision = ? then 1 else 0 end) as reviewed, sum(input_tokens) as input_tokens, sum(cached_tokens) as cached_tokens, sum(cache_write_tokens) as cache_write_tokens, sum(output_tokens) as output_tokens, sum(estimated_total_cost) as cost', ['adopt', 'reject', 'review'])
            ->toBase()->get();

        // The reason classes counted over the latest screening of each document, in the order of the gate's list.
        $counted = Screening::query()->where('status', 'screened')->whereIn('id', Document::query()->whereNotNull('latest_screening_id')->select('latest_screening_id'))->groupBy('reason_class')->selectRaw('reason_class, count(*) as count')->pluck('count', 'reason_class');
        $total = max(1, (int) $counted->sum());
        $reasons = [];

        foreach (Screening::REASONS as $reason => $about) {
            $reasons[] = ['reason_class' => $reason, 'decision' => $about['decision'], 'meaning' => $about['meaning'], 'count' => (int) ($counted[$reason] ?? 0), 'share' => (int) ($counted[$reason] ?? 0) / $total];
        }

        return [
            'versions' => array_values($versions->map(fn ($row): array => [
                'version' => (int) $row->version,
                'screened' => (int) $row->screened,
                'adopted' => (int) $row->adopted,
                'rejected' => (int) $row->rejected,
                'reviewed' => (int) $row->reviewed,
                'cache_hit_rate' => $row->input_tokens > 0 ? $row->cached_tokens / $row->input_tokens : null,
                'cache_write_rate' => $row->input_tokens > 0 ? $row->cache_write_tokens / $row->input_tokens : null,
                'average_cost' => $row->cost !== null ? $row->cost / $row->screened : null,
                'cost_per_adopt' => $row->cost !== null && $row->adopted > 0 ? $row->cost / $row->adopted : null,
            ])->all()),
            'reasons' => $reasons,
        ];
    }
}
