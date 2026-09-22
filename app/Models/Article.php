<?php

namespace App\Models;

use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 記事 (UI: "Articles"): generated from a material per the editorial
 * policy by App\Jobs\GenerateArticle; a draft until it is published.
 * Status generating / draft / failed / published (UI: 生成中 / 下書き /
 * 失敗 / 公開済み). Pinned, like a screening and a material, to the
 * prompt version and the model it was written with, with the usage of
 * the call, so articles written under different policies can be compared.
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    public const STATUSES = ['generating', 'draft', 'failed', 'published'];

    protected $fillable = [
        'material_id', 'prompt_id', 'model', 'title', 'body', 'status', 'status_message', 'published_at',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'estimated_total_cost' => 'float'];
    }

    /**
     * What the screens call the article: its title once generated, the
     * document's title until then.
     */
    public function displayTitle(): string
    {
        return $this->title ?? (string) $this->material?->document->title;
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return BelongsTo<Prompt, $this> the version of the article generation layer it was written with */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }
}
