<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 編集方針 (UI: "Editorial policy"): what each stage decides by, one body
 * of text per layer (docs/HANDOVER.md §1). The selection layer (UI:
 * 取捨選択, set on the 情報源 screen) is three bodies: the exclude
 * keywords that keep a document from being fetched, and the criteria
 * for / against fetching, meant for an LLM judge that is not built yet.
 * The structuring layer is the prompt that turns a document into a
 * material (stage 2.3), the article layer the one that turns a material
 * into an article (stage 2.4).
 */
class EditorialPolicy extends Model
{
    /** The layers, in flow order: 取捨選択 (three bodies) / 構造化 / 記事生成. */
    public const LAYERS = ['exclude_keywords', 'fetch_criteria', 'skip_criteria', 'structuring', 'article'];

    /**
     * What a layer says until someone edits it on the screen. The items
     * of the structuring layer ("- item: …") become the keys of the JSON.
     */
    public const DEFAULTS = [
        'exclude_keywords' => '',
        'fetch_criteria' => '',
        'skip_criteria' => '',
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
     * The exclude keywords (UI: "Exclude keywords"): one rule per line; a
     * line of several words separated by semicolons is one rule that
     * needs all of them in the title (掲載 alone would take real news
     * with it, 寄稿; 掲載 does not).
     *
     * @return list<list<string>> each rule's words
     */
    public static function excludeKeywords(): array
    {
        $rules = [];

        foreach (preg_split('/\R/u', self::bodyFor('exclude_keywords')) ?: [] as $line) {
            $words = array_values(array_filter(array_map(trim(...), preg_split('/[;；]/u', $line) ?: []), fn (string $word): bool => $word !== ''));

            if ($words !== []) {
                $rules[] = $words;
            }
        }

        return $rules;
    }

    /**
     * The first exclude rule whose words a document's title all contains
     * (case does not matter), written as "word; word", or null when the
     * document is to be fetched.
     */
    public static function excludedBy(string $title): ?string
    {
        foreach (self::excludeKeywords() as $words) {
            if (array_all($words, fn (string $word): bool => mb_stripos($title, $word) !== false)) {
                return implode('; ', $words);
            }
        }

        return null;
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
