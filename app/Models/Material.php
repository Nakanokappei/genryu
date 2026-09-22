<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 素材情報 (UI: "Materials"): the structure extracted from a document per
 * the editorial policy, stored as JSON so the shape
 * can evolve. Extracted in the background by App\Jobs\ExtractMaterial;
 * status extracting / extracted / failed (UI: 抽出中 / 抽出済み / 失敗).
 *
 * @property array<string, mixed>|null $data one value per item of the structuring layer
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $fillable = ['document_id', 'data', 'status', 'status_message'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /**
     * The data with its keys in the order the structuring layer of the
     * editorial policy lists them (jsonb stores keys sorted by length and
     * letter), any other key after them.
     *
     * @return array<string, mixed>|null
     */
    public function dataInPolicyOrder(): ?array
    {
        if ($this->data === null) {
            return null;
        }

        $ordered = [];

        foreach (EditorialPolicy::items(EditorialPolicy::bodyFor('structuring')) as $item) {
            if (array_key_exists($item, $this->data)) {
                $ordered[$item] = $this->data[$item];
            }
        }

        return [...$ordered, ...$this->data];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
