<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;
use Illuminate\Validation\Rule;

/**
 * 編集方針 (UI: "Editorial policy"): what each stage decides by, one body
 * of text per layer (docs/HANDOVER.md §1). The selection layer (UI:
 * 取捨選択) is two bodies: the title filter (exclude rules that keep a
 * document from being fetched, set on the 情報源 screen) and the content
 * filtering (the developer prompt of the スクリーニング, set on the 文書
 * screen). The prompts are written in English and answer in the language
 * of the document they read; the screens around them are Japanese.
 * The structuring layer is the prompt that turns a document into a
 * material (stage 2.3, set on the 素材情報 screen): the parts an article
 * is made of. The headline layer turns a material into the headline of
 * its article, in the language of its primary source, and judges it; the
 * article layer writes the body under that headline; the translation
 * layer turns the article into the other languages we publish in (stage
 * 2.4). All three are set on the 記事 screen, in that order.
 */
class EditorialPolicy extends Model
{
    /** The layers in flow order, each with its UI label. */
    public const LAYER_LABELS = [
        'exclude_keywords' => 'Title filter',
        'semantic_filter' => 'Semantic filter',
        'semantic_like' => 'Like this media',
        'semantic_unlike' => 'Unlike this media',
        'content_filtering' => 'Content filtering',
        'structuring' => 'Structuring',
        'headline' => 'Headline',
        'article' => 'Article generation',
        'translation' => 'Translation',
        'quality' => 'Quality check',
        'image' => 'Image',
    ];

    /**
     * What a layer says until someone edits it on the screen: nothing.
     * Prompts are assets and never go into the repository (decided
     * 2026-09-25, the repository is public); they live in the database
     * and are copied to and from prompts/, kept out of Git, by
     * prompts:export and prompts:import.
     */
    public const DEFAULTS = [
        'exclude_keywords' => '',
        'semantic_like' => '',
        'semantic_unlike' => '',
        'content_filtering' => '',
        'structuring' => '',
        'headline' => '',
        'article' => '',
        'quality' => '',
        'image' => '',
        'translation' => '',
    ];

    /**
     * The models the content filtering can run on (UI: 初回判定モデル), by the id
     * the API takes, weakest first: the name shown, and what each one is
     * for. A document the screening sends to review (要確認) is judged
     * again by the next model up, so the strongest cannot be the model
     * of the screening itself.
     */
    public const TEXT_MODELS = [
        'gpt-5.6-luna' => ['name' => 'GPT-5.6 Luna', 'description' => 'For bulk work where cost matters most'],
        'gpt-5.6-terra' => ['name' => 'GPT-5.6 Terra', 'description' => 'A balance of judgement and cost'],
        'gpt-5.6-sol' => ['name' => 'GPT-5.6 Sol', 'description' => 'For complex specialist work; gpt-5.6 is an alias of this model'],
        'gpt-6-astra' => ['name' => 'GPT-6 Astra', 'description' => 'For work that needs especially hard reasoning'],
    ];

    /**
     * The models that draw a top image (UI 画像モデル), by the id the Images
     * API takes: the name shown and what each one is for.
     */
    public const IMAGE_MODELS = [
        'gpt-image-2.5-flare' => ['name' => 'GPT Image 2.5 Flare', 'description' => 'Fast, high-quality everyday image generation'],
        'gpt-image-2.5-sunburst' => ['name' => 'GPT Image 2.5 Sunburst', 'description' => 'The most capable, where precision matters most'],
    ];

    /** The image model until one is chosen. */
    public const DEFAULT_IMAGE_MODEL = 'gpt-image-2.5-flare';

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
        return array_slice(array_keys(self::TEXT_MODELS), 0, -1);
    }

    /**
     * The validation rule for a model chosen on a screen.
     *
     * @param  array<string, mixed>|list<string>  $models  a list of ids, or models keyed by id
     * @return list<mixed>
     */
    public static function modelRule(array $models = self::TEXT_MODELS): array
    {
        return ['required', Rule::in(array_is_list($models) ? $models : array_keys($models))];
    }

    /**
     * The model one up from a model, for judging again a document the
     * screening sent to review; the strongest model is its own next.
     */
    public static function nextModelUp(string $model): string
    {
        $ids = array_keys(self::TEXT_MODELS);
        $index = array_search($model, $ids, true);

        return $ids[min(count($ids) - 1, ($index === false ? 0 : $index) + 1)];
    }

    /**
     * The embedding models the semantic filter can run on (UI 埋め込みモデル),
     * by the id the Embeddings API takes. Likeness from one model is not
     * comparable with another's: a changed model embeds every document
     * again, and the threshold wants looking at.
     */
    public const EMBEDDING_MODELS = [
        'text-embedding-3-large' => ['name' => 'text-embedding-3-large', 'description' => 'Finer distinctions, at a few cents a day for arXiv'],
        'text-embedding-3-small' => ['name' => 'text-embedding-3-small', 'description' => 'Cheaper, coarser'],
    ];

    /** The embedding model until one is chosen. */
    public const DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-large';

    /**
     * The threshold of likeness (UI 閾値) until one is chosen: set on
     * 2026-09-24 from 600 arXiv papers, where below +0.10 few primary
     * sources were worth reading.
     */
    public const DEFAULT_THRESHOLD = 0.10;

    protected $fillable = ['layer', 'body', 'model', 'image_model', 'threshold'];

    /**
     * The threshold of likeness, read once per request: every row of a
     * list of documents asks for it. A saved policy forgets it.
     */
    public static function likenessThreshold(): float
    {
        return once(fn (): float => self::semanticFilter()['threshold']);
    }

    protected static function booted(): void
    {
        static::saved(fn () => Once::flush());
    }

    /**
     * The semantic filter as set (or the defaults): its definitions, one
     * per line on a screen of each side (layers semantic_like, UI らしい,
     * and semantic_unlike, UI らしくない), and the embedding model and the
     * threshold of likeness set on 文書 (layer semantic_filter).
     *
     * @return array{definitions: list<array{side: string, text: string}>, model: string, threshold: float}
     */
    public static function semanticFilter(): array
    {
        $policy = static::query()->where('layer', 'semantic_filter')->first();
        $definitions = [];

        foreach (['like' => 'semantic_like', 'unlike' => 'semantic_unlike'] as $side => $layer) {
            foreach (preg_split('/\R/u', self::bodyFor($layer)) ?: [] as $line) {
                if (trim($line) !== '') {
                    $definitions[] = ['side' => $side, 'text' => trim($line)];
                }
            }
        }

        return [
            'definitions' => $definitions,
            'model' => $policy !== null && array_key_exists((string) $policy->model, self::EMBEDDING_MODELS) ? (string) $policy->model : self::DEFAULT_EMBEDDING_MODEL,
            'threshold' => $policy?->threshold !== null ? (float) $policy->threshold : self::DEFAULT_THRESHOLD,
        ];
    }

    /** The model that draws the top images: what was chosen on 画像, or the default. */
    public static function imageModel(): string
    {
        return (string) (static::query()->where('layer', 'image')->value('image_model') ?? self::DEFAULT_IMAGE_MODEL);
    }

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
            if (array_all($words, fn (string $word): bool => preg_match(self::wordPattern($word), $title) === 1)) {
                return implode('; ', $words);
            }
        }

        return null;
    }

    /**
     * A keyword as a whole word: serving must not be found in observing,
     * nor LLM in LLMs. A trailing * lets the word go on (memoriz* for
     * memorize and memorization, LLM* for LLMs). A keyword that begins
     * or ends in Chinese, Japanese or Korean has no word boundary on that
     * side, since those scripts write words without spaces (掲載 is in
     * 掲載されました).
     */
    private static function wordPattern(string $word): string
    {
        $isPrefix = str_ends_with($word, '*');
        $word = $isPrefix ? rtrim($word, '*') : $word;
        $bounded = fn (string $char): bool => preg_match('/^[\p{L}\p{N}]$/u', $char) === 1 && preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]$/u', $char) === 0;

        $before = $bounded(mb_substr($word, 0, 1)) ? '(?<![\p{L}\p{N}])' : '';
        $after = ! $isPrefix && $bounded(mb_substr($word, -1)) ? '(?![\p{L}\p{N}])' : '';

        return '/'.$before.preg_quote($word, '/').$after.'/iu';
    }
}
