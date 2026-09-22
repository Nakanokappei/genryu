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
 * 失敗 / 公開済み).
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    public const STATUSES = ['generating', 'draft', 'failed', 'published'];

    protected $fillable = ['material_id', 'title', 'body', 'status', 'status_message', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /**
     * What the screens call the article: its title once generated, the
     * update entry's title until then.
     */
    public function displayTitle(): string
    {
        return $this->title ?? (string) $this->material?->updateEntry->title;
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
