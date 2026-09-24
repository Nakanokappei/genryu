<?php

namespace App\Models;

use App\Actions\ProposeMaterial;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 素材情報 (UI "Materials"): the parts an article is made of, extracted
 * from one document revision. Status extracting / extracted / failed
 * (UI 抽出中 / 抽出済み / 失敗).
 *
 * @property array<string, mixed>|null $parts the parts the model could write, plus figures (図版)
 * @property list<string>|null $failed_checks the last validation's problems, empty when it passed
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
     * The parts in ProposeMaterial::PARTS order (jsonb sorts keys by length).
     *
     * @return array<string, string|list<string>>
     */
    public function parts(): array
    {
        $data = (array) $this->parts;
        $ordered = [];

        // Pick the parts present, in schema order.
        foreach (ProposeMaterial::PARTS as $part) {
            if (array_key_exists($part, $data)) {
                $ordered[$part] = $data[$part];
            }
        }

        return $ordered;
    }

    /**
     * Line counts by what they stand on: primary source, general knowledge, inference.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(ProposeMaterial::LISTS, 0);

        // Add each list's lines to what it stands on.
        foreach (ProposeMaterial::LISTS as $part => $stands) {
            $counts[$stands] += count((array) ($this->parts[$part] ?? []));
        }

        return $counts;
    }

    /**
     * The source's figures (図版), in source order.
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
