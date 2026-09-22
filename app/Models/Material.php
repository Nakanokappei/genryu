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
 * editorial policy and stored as JSON (data): one entry per item the
 * policy lists, with its value, where it came from (the document, with
 * the lines it quotes; the model\'s general knowledge; or nowhere) and
 * those quotes. Pinned to the
 * document revision it was made from, the prompt version and the model;
 * status extracting / extracted / failed (UI: 抽出中 / 抽出済み / 失敗),
 * with the report of the checks (validation) and the usage of the calls.
 *
 * @property array<string, mixed>|null $data one entry per item of the structuring layer
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
     * The material item by item, in the order the structuring layer
     * lists them (jsonb stores keys sorted by length and letter), any
     * other key after them; each with its value, where it came from and
     * its quotes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function items(): array
    {
        $data = $this->data ?? [];
        $ordered = [];

        foreach (EditorialPolicy::items(EditorialPolicy::bodyFor('structuring')) as $item) {
            if (array_key_exists($item, $data)) {
                $ordered[$item] = $data[$item];
            }
        }

        return [...$ordered, ...$data];
    }

    /**
     * How many items came from the document and how many from the
     * model's general knowledge: what this PoC is out to measure.
     *
     * @return array<string, int>
     */
    public function sources(): array
    {
        $counts = array_fill_keys(ProposeMaterial::SOURCES, 0);

        foreach ($this->items() as $item) {
            $source = (string) ($item['source'] ?? 'none');
            $counts[$source] = ($counts[$source] ?? 0) + 1;
        }

        return $counts;
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
