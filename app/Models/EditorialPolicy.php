<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 編集方針 (UI: "Editorial policy"): what each stage decides by, one body
 * of text per layer (docs/HANDOVER.md §1). The structuring layer is the
 * prompt that turns a document into a material (stage 2.3); the other
 * two wait for their stages.
 */
class EditorialPolicy extends Model
{
    /** The layers, in flow order: 取捨選択 / 構造化 / 記事生成. */
    public const LAYERS = ['selection', 'structuring', 'article'];

    /**
     * What a layer says until someone edits it on the screen. The items
     * of the structuring layer ("- item: …") become the keys of the JSON.
     */
    public const DEFAULTS = [
        'selection' => '',
        'structuring' => '',
        'article' => '',
    ];

    protected $fillable = ['layer', 'body'];

    /**
     * The body of a layer: what was saved, or the default until then.
     */
    public static function bodyFor(string $layer): string
    {
        return (string) (static::query()->where('layer', $layer)->value('body') ?? self::DEFAULTS[$layer] ?? '');
    }

    /**
     * The items a layer lists as "- item: …" lines, in order: for the
     * structuring layer, the keys every material must have.
     *
     * @return list<string>
     */
    public static function items(string $body): array
    {
        preg_match_all('/^\s*[-*]\s*([^:：\n]+?)\s*[:：]/mu', $body, $matches);

        return array_values(array_unique($matches[1]));
    }
}
