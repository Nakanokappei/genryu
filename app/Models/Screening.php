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
 * model named, the fact it pointed at and its short reason. A first
 * pass (pass 1) may answer review, and then the job runs a second pass
 * (pass 2) by the next model up, which decides adopt or reject: nobody
 * reviews by hand, and a reject is final. The tokens
 * (cached and cache-written ones apart), the latency and the estimated
 * cost are kept for the cache and cost figures on the 文書 screen. The run
 * pins the document revision it read (document_revision_id).
 */
class Screening extends Model
{
    /** @use HasFactory<ScreeningFactory> */
    use HasFactory;

    public const DECISIONS = ['adopt', 'reject', 'review'];

    /** The reason classes of the gate, each with the decision it belongs to and what it means (UI 理由の内訳). */
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

    /** The reason classes of the gate, in the order of REASONS. */
    public const PRIMARY_REASONS = ['FRONTIER_BREAK', 'FEASIBILITY_BET', 'ENGINEERING_ATTACK', 'DEMONSTRATION', 'INDUSTRIALIZATION', 'ECONOMIC_TRANSITION', 'COMPETITION_DIFFUSION', 'IMPORTANT_FAILURE', 'REGULATION_STANDARD', 'PURE_SCIENCE', 'ROUTINE_PRODUCT', 'GENERAL_CORPORATE', 'EVENT_PR', 'ADMINISTRATIVE', 'OPINION_ONLY', 'TECHNOLOGY_USE_ONLY', 'INSUFFICIENT_EVIDENCE'];

    protected $fillable = [
        'document_id', 'document_revision_id', 'prompt_id', 'model', 'pass', 'status', 'status_message',
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

    /** @return BelongsTo<Prompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /** @return BelongsTo<DocumentRevision, $this> the Markdown the decision was made on */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'document_revision_id');
    }
}
