<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One drawing of an article's トップ画像: the scene, the image prompt, the
 * time and band it was drawn for, and the file. Status making / made /
 * failed (UI 作成中 / 作成済み / 失敗).
 */
class ArticleImage extends Model
{
    protected $fillable = [
        'article_id', 'prompt_id', 'scene_model', 'image_model', 'palette', 'time', 'band', 'status', 'status_message', 'scene', 'image_prompt', 'path',
        'input_tokens', 'output_tokens', 'image_input_tokens', 'image_output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['estimated_total_cost' => 'float'];
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
