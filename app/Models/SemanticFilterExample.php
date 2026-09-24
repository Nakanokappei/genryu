<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 意味フィルタの例 (UI "Semantic filter example"): a document a person marked
 * as like or unlike this media; it teaches the filter, it is not a verdict.
 */
class SemanticFilterExample extends Model
{
    /** The sides, UI らしい / らしくない. */
    public const SIDES = ['like' => 'Like this media', 'unlike' => 'Unlike this media'];

    /** The layer holding a side's definitions. */
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
