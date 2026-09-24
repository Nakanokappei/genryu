<?php

namespace App\Models;

use Database\Factories\ScreeningFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * スクリーニング (UI "Screening"): one pass of the screening gate on a
 * document revision. Status screening / screened / failed (UI 判定中 /
 * 判定済み / 失敗); pass 1 or 2 (the second pass after a review).
 */
class Screening extends Model
{
    /** @use HasFactory<ScreeningFactory> */
    use HasFactory;

    /** UI 採用 / 不採用 / 要確認. */
    public const DECISIONS = ['adopt', 'reject', 'review'];

    /** The reason classes (reason_class, UI 理由の内訳), each with its decision and meaning. */
    public const REASONS = [
        'FRONTIER_BREAK' => ['decision' => 'adopt', 'meaning' => 'Something impossible became an engineering problem'],
        'FEASIBILITY_BET' => ['decision' => 'adopt', 'meaning' => 'A backer who can act bet that a capability is now solvable'],
        'ENGINEERING_ATTACK' => ['decision' => 'adopt', 'meaning' => 'Concrete engineering has begun on a scientific possibility'],
        'DEMONSTRATION' => ['decision' => 'adopt', 'meaning' => 'Moved from the laboratory to a real environment'],
        'INDUSTRIALIZATION' => ['decision' => 'adopt', 'meaning' => 'From making it to making it in volume'],
        'ECONOMIC_TRANSITION' => ['decision' => 'adopt', 'meaning' => 'Technically possible became economically real'],
        'COMPETITION_DIFFUSION' => ['decision' => 'adopt', 'meaning' => 'Competing with, replacing or spreading past existing technology'],
        'IMPORTANT_FAILURE' => ['decision' => 'adopt', 'meaning' => 'A failure that changes what is thought feasible'],
        'REGULATION_STANDARD' => ['decision' => 'adopt', 'meaning' => 'Regulation, a standard or a certification changed feasibility'],
        'PURE_SCIENCE' => ['decision' => 'reject', 'meaning' => 'Basic research with no move toward engineering'],
        'ROUTINE_PRODUCT' => ['decision' => 'reject', 'meaning' => 'An ordinary product, model or update'],
        'GENERAL_CORPORATE' => ['decision' => 'reject', 'meaning' => 'Personnel, organisation, partnerships, M&A, funding, results'],
        'EVENT_PR' => ['decision' => 'reject', 'meaning' => 'Seminars, exhibitions, talks, awards, CSR, publicity'],
        'ADMINISTRATIVE' => ['decision' => 'reject', 'meaning' => 'Schedules, deadlines, venues, corrections, routine reports'],
        'OPINION_ONLY' => ['decision' => 'reject', 'meaning' => 'Visions, forecasts and roadmaps without new evidence'],
        'TECHNOLOGY_USE_ONLY' => ['decision' => 'reject', 'meaning' => 'Existing technology merely put to use'],
        'INSUFFICIENT_EVIDENCE' => ['decision' => 'review', 'meaning' => 'A possible transition the text alone cannot confirm'],
    ];

    protected $fillable = [
        'document_id', 'document_revision_id', 'prompt_id', 'model', 'pass', 'status', 'status_message',
        'decision', 'reason_class', 'evidence', 'reason',
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

    /** @return BelongsTo<Prompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /** @return BelongsTo<DocumentRevision, $this> the revision it read */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'document_revision_id');
    }
}
