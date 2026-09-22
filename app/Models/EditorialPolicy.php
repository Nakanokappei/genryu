<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 編集方針 (UI: "Editorial policy"): what each stage decides by, one body
 * of text per layer (docs/HANDOVER.md §1). The selection layer (UI:
 * 取捨選択) is two bodies: the title filter (exclude rules that keep a
 * document from being fetched, set on the 情報源 screen) and the content
 * filtering (the developer prompt of an LLM that reads a fetched document,
 * set on the 文書 screen; the judge is not built yet).
 * The structuring layer is the prompt that turns a document into a
 * material (stage 2.3), the article layer the one that turns a material
 * into an article (stage 2.4).
 */
class EditorialPolicy extends Model
{
    /** The layers, in flow order: 取捨選択 (the title filter, then the content filtering) / 構造化 / 記事生成. */
    public const LAYERS = ['exclude_keywords', 'content_filtering', 'structuring', 'article'];

    /**
     * What a layer says until someone edits it on the screen. The items
     * of the structuring layer ("- item: …") become the keys of the JSON.
     */
    public const DEFAULTS = [
        'exclude_keywords' => '',
        'content_filtering' => '',
        'structuring' => '',
        'article' => '',
    ];

    /**
     * The models the content filtering can run on (UI: モデル), by the id
     * the API takes, weakest first: the name shown, and what each one is
     * for. A document the screening sends to review (要確認) is judged
     * again by the next model up, so the strongest cannot be the model
     * of the screening itself.
     */
    public const MODELS = [
        'gpt-5.6-luna' => ['name' => 'GPT-5.6 Luna', 'description' => 'For bulk work where cost matters most'],
        'gpt-5.6-terra' => ['name' => 'GPT-5.6 Terra', 'description' => 'A balance of judgement and cost'],
        'gpt-5.6-sol' => ['name' => 'GPT-5.6 Sol', 'description' => 'For complex specialist work; gpt-5.6 is an alias of this model'],
        'gpt-6-astra' => ['name' => 'GPT-6 Astra', 'description' => 'For work that needs especially hard reasoning'],
    ];

    /** The model the content filtering runs on until one is chosen. */
    public const DEFAULT_MODEL = 'gpt-5.6-terra';

    /**
     * The models that can be chosen for the screening: all but the
     * strongest, which is kept for reviewing.
     *
     * @return list<string>
     */
    public static function screeningModels(): array
    {
        return array_slice(array_keys(self::MODELS), 0, -1);
    }

    /**
     * The model one up from a model, for judging again a document the
     * screening sent to review; the strongest model is its own next.
     */
    public static function nextModelUp(string $model): string
    {
        $ids = array_keys(self::MODELS);
        $index = array_search($model, $ids, true);

        return $ids[min(count($ids) - 1, ($index === false ? 0 : $index) + 1)];
    }

    protected $fillable = ['layer', 'body', 'model'];

    /**
     * The model a layer runs on: what was chosen, or the default.
     */
    public static function modelFor(string $layer): string
    {
        return (string) (static::query()->where('layer', $layer)->value('model') ?? self::DEFAULT_MODEL);
    }

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
     * document is to be fetched. The rules are read from the policy
     * unless given (a caller going through many titles reads them once).
     *
     * @param  list<list<string>>|null  $rules
     */
    public static function excludedBy(string $title, ?array $rules = null): ?string
    {
        foreach ($rules ?? self::excludeKeywords() as $words) {
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
