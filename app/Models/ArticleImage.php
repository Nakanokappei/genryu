<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One drawing of an article's top image (トップ画像, App\Jobs\MakeImage): the
 * scene the writer chose from the article, the prompt the image model was
 * given — the scene, the style of the time band and what may not be
 * drawn — the local time of day and the band it was made for, and where
 * the image is kept. Status making / made / failed (UI 作成中 / 作成済み /
 * 失敗). Pins the prompt version and both models, keeps the usage.
 */
class ArticleImage extends Model
{
    protected $fillable = [
        'article_id', 'prompt_id', 'scene_model', 'image_model', 'time', 'band', 'status', 'status_message', 'scene', 'image_prompt', 'path',
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
