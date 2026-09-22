<?php

namespace App\Models;

use Database\Factories\ScreeningFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * スクリーニング (UI: "Screening"): one run of the Editorial Screening Gate
 * on a document, by App\Jobs\ScreenDocument. Status screening / screened /
 * failed (UI: 判定中 / 判定済み / 失敗); once screened, the decision adopt /
 * reject / review (UI: 採用 / 不採用 / 要確認) with the reason class the
 * model named, the fact it pointed at and its short reason. The tokens
 * (cached and cache-written ones apart), the latency and the estimated
 * cost are kept for the cache and cost figures on the 文書 screen.
 */
class Screening extends Model
{
    /** @use HasFactory<ScreeningFactory> */
    use HasFactory;

    public const DECISIONS = ['adopt', 'reject', 'review'];

    /** The reason classes of the gate: the first eight adopt, the next seven reject, the last one is review. */
    public const PRIMARY_REASONS = [
        'FRONTIER_BREAK', 'ENGINEERING_ATTACK', 'DEMONSTRATION', 'INDUSTRIALIZATION', 'ECONOMIC_TRANSITION', 'COMPETITION_DIFFUSION', 'IMPORTANT_FAILURE', 'REGULATION_STANDARD',
        'PURE_SCIENCE', 'ROUTINE_PRODUCT', 'GENERAL_CORPORATE', 'EVENT_PR', 'ADMINISTRATIVE', 'OPINION_ONLY', 'TECHNOLOGY_USE_ONLY',
        'INSUFFICIENT_EVIDENCE',
    ];

    protected $fillable = [
        'document_id', 'screening_prompt_id', 'model', 'status', 'status_message',
        'decision', 'primary_reason', 'evidence', 'reason',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms',
        'estimated_input_cost', 'estimated_output_cost', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['estimated_input_cost' => 'float', 'estimated_output_cost' => 'float', 'estimated_total_cost' => 'float'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<ScreeningPrompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(ScreeningPrompt::class, 'screening_prompt_id');
    }
}
