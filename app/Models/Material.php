<?php

namespace App\Models;

use App\Actions\ProposeMaterial;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 素材情報 (UI: "Materials"): what a document yields for the articles,
 * built by App\Jobs\ExtractMaterial per the structuring layer of the
 * editorial policy and stored as JSON (data): the parts an article is
 * made of — the angle it would be written on, what was true before,
 * what this document changes, what may follow, the facts the primary
 * source gives and the background the model fills in from its own
 * general knowledge. Nothing about itself: a part the model cannot
 * write plainly is absent rather than hedged. Pinned to the
 * document revision it was made from, the prompt version and the model;
 * status extracting / extracted / failed (UI: 抽出中 / 抽出済み / 失敗),
 * with the report of the checks (validation) and the usage of the calls.
 *
 * @property array<string, mixed>|null $data the parts of the article, only those the model could write
 * @property list<string>|null $validation the problems the last checks found, empty when they passed
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id', 'document_revision_id', 'prompt_id', 'model', 'data', 'status', 'status_message', 'validation',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'validation' => 'array', 'estimated_total_cost' => 'float'];
    }

    /**
     * The material part by part, in the order the policy asks for them
     * (jsonb stores keys sorted by length and letter).
     *
     * @return array<string, string|list<string>>
     */
    public function parts(): array
    {
        $data = (array) $this->data;
        $ordered = [];

        foreach (ProposeMaterial::PARTS as $part) {
            if (array_key_exists($part, $data)) {
                $ordered[$part] = $data[$part];
            }
        }

        return $ordered;
    }

    /**
     * How many lines come from the primary source (facts) and how many
     * from the model's own general knowledge (background): what this PoC
     * is out to measure.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return array_map(fn (string $part): int => count((array) ($this->data[$part] ?? [])), array_flip(ProposeMaterial::LISTS));
    }

    /** @return BelongsTo<DocumentRevision, $this> the Markdown the material was made from */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'document_revision_id');
    }

    /** @return BelongsTo<Prompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
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
