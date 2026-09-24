<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 品質チェック (UI "Quality check"): one scoring of an article, score 0–100
 * (UI 品質) with its reason. Status checking / checked / failed
 * (UI チェック中 / チェック済み / 失敗).
 */
class QualityCheck extends Model
{
    protected $fillable = [
        'article_id', 'prompt_id', 'model', 'status', 'status_message', 'score', 'reason',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['score' => 'integer', 'estimated_total_cost' => 'float'];
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<Prompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }
}
