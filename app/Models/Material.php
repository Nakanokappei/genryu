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
 * source gives, the background the model fills in from its own general
 * knowledge, and what it infers from both: who gains, who loses, what
 * everyday life looks like if this holds. Beside them, the figures of the
 * source (図版, `figures`: URL, alt text and caption), gathered by
 * App\Actions\CollectFigures from its Markdown rather than by the model. Nothing about itself: a part
 * the model cannot write plainly is absent rather than hedged. Pinned to the
 * document revision it was made from, the prompt version and the model;
 * status extracting / extracted / failed (UI: 抽出中 / 抽出済み / 失敗),
 * with the checks it failed (failed_checks) and the usage of the calls.
 *
 * @property array<string, mixed>|null $parts the parts of the article, only those the model could write
 * @property list<string>|null $failed_checks the problems the last checks found, empty when they passed
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id', 'document_revision_id', 'prompt_id', 'model', 'parts', 'status', 'status_message', 'failed_checks',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['parts' => 'array', 'failed_checks' => 'array', 'estimated_total_cost' => 'float'];
    }

    /**
     * The material part by part, in the order the policy asks for them
     * (jsonb stores keys sorted by length and letter).
     *
     * @return array<string, string|list<string>>
     */
    public function parts(): array
    {
        $data = (array) $this->parts;
        $ordered = [];

        foreach (ProposeMaterial::PARTS as $part) {
            if (array_key_exists($part, $data)) {
                $ordered[$part] = $data[$part];
            }
        }

        return $ordered;
    }

    /**
     * How many lines stand on the primary source, how many on the
     * model's own general knowledge, and how many on inference from
     * both: what this PoC is out to measure.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(ProposeMaterial::LISTS, 0);

        foreach (ProposeMaterial::LISTS as $part => $stands) {
            $counts[$stands] += count((array) ($this->parts[$part] ?? []));
        }

        return $counts;
    }

    /**
     * The figures of the source, in the order they appear there.
     *
     * @return list<array{url: string, alt: string, caption: ?string}>
     */
    public function figures(): array
    {
        return array_values((array) ($this->parts['figures'] ?? []));
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
