<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 意味フィルタの例 (UI: "Semantic filter example"): a document a person
 * marked as like this media (らしい) or unlike it (らしくない). The
 * 意味フィルタ measures every document against the examples as well as
 * the definitions, so the filter learns from what a person marks after
 * the fact. An example is not a verdict: it does not adopt or reject the
 * document (人の判定 does that).
 */
class SemanticFilterExample extends Model
{
    /** The two sides: like this media (らしい) and unlike it (らしくない). */
    public const SIDES = ['like' => 'Like this media', 'unlike' => 'Unlike this media'];

    /** The editorial policy layer that holds a side's definitions. */
    public static function layerOf(string $side): string
    {
        return 'semantic_'.$side;
    }

    protected $fillable = ['document_id', 'side', 'created_by'];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
