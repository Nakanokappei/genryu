<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;
use Illuminate\Validation\Rule;

/**
 * Editorial policy: one body (and model) per layer, each edited on the
 * screen it governs (title filter on 情報源, content filtering on 文書,
 * structuring on 素材情報, headline / article / translation on 記事, …).
 */
class EditorialPolicy extends Model
{
    /** The layers in flow order, each with its UI label. */
    public const LAYER_LABELS = [
        'title_filter' => 'Title filter',
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

    /** Every layer is empty until edited: prompts live in the database only. */
    public const DEFAULTS = [
        'title_filter' => '',
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

    /** The text models by API id, weakest first, with name and description (UI 初回判定モデル). */
    public const TEXT_MODELS = [
        'gpt-5.6-luna' => ['name' => 'GPT-5.6 Luna', 'description' => 'For bulk work where cost matters most'],
        'gpt-5.6-terra' => ['name' => 'GPT-5.6 Terra', 'description' => 'A balance of judgement and cost'],
        'gpt-5.6-sol' => ['name' => 'GPT-5.6 Sol', 'description' => 'For complex specialist work; gpt-5.6 is an alias of this model'],
        'gpt-6-astra' => ['name' => 'GPT-6 Astra', 'description' => 'For work that needs especially hard reasoning'],
    ];

    /** The image models by API id (UI 画像モデル). */
    public const IMAGE_MODELS = [
        'gpt-image-2.5-flare' => ['name' => 'GPT Image 2.5 Flare', 'description' => 'Fast, high-quality everyday image generation'],
        'gpt-image-2.5-sunburst' => ['name' => 'GPT Image 2.5 Sunburst', 'description' => 'The most capable, where precision matters most'],
    ];

    /** The image model until one is chosen. */
    public const DEFAULT_IMAGE_MODEL = 'gpt-image-2.5-flare';

    /** The text model until one is chosen. */
    public const DEFAULT_MODEL = 'gpt-5.6-terra';

    /**
     * The models the screening can run on: all but the strongest, kept for the second pass.
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

    /** The next model up for the second pass (the strongest stays itself). */
    public static function nextModelUp(string $model): string
    {
        $ids = array_keys(self::TEXT_MODELS);
        $index = array_search($model, $ids, true);

        return $ids[min(count($ids) - 1, ($index === false ? 0 : $index) + 1)];
    }

    /** The embedding models by API id (UI 埋め込みモデル); likeness is not comparable across them. */
    public const EMBEDDING_MODELS = [
        'text-embedding-3-large' => ['name' => 'text-embedding-3-large', 'description' => 'Finer distinctions, at a few cents a day for arXiv'],
        'text-embedding-3-small' => ['name' => 'text-embedding-3-small', 'description' => 'Cheaper, coarser'],
    ];

    /** The embedding model until one is chosen. */
    public const DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-large';

    /** 閾値 of likeness until one is chosen. */
    public const DEFAULT_THRESHOLD = 0.10;

    protected $fillable = ['layer', 'body', 'model', 'image_model', 'threshold'];

    /** The likeness threshold, memoised per request (a save flushes it). */
    public static function likenessThreshold(): float
    {
        return once(fn (): float => self::semanticFilter()['threshold']);
    }

    /** Flushes memoised values on save. */
    protected static function booted(): void
    {
        static::saved(fn () => Once::flush());
    }

    /**
     * The semantic filter: the definitions of both sides (one per line), its model and threshold.
     *
     * @return array{definitions: list<array{side: string, text: string}>, model: string, threshold: float}
     */
    public static function semanticFilter(): array
    {
        $policy = static::query()->where('layer', 'semantic_filter')->first();
        $definitions = [];

        // Every non-blank line of each side is a definition.
        foreach (array_keys(SemanticFilterExample::SIDES) as $side) {
            foreach (preg_split('/\R/u', self::bodyFor(SemanticFilterExample::layerOf($side))) ?: [] as $line) {
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

    /** The image model chosen on 画像, or the default. */
    public static function imageModel(): string
    {
        return (string) (static::query()->where('layer', 'image')->value('image_model') ?? self::DEFAULT_IMAGE_MODEL);
    }

    /** A layer's model, or the default. */
    public static function modelFor(string $layer): string
    {
        return (string) (static::query()->where('layer', $layer)->value('model') ?? self::DEFAULT_MODEL);
    }

    /** A layer's body, or the default. */
    public static function bodyFor(string $layer): string
    {
        return (string) (static::query()->where('layer', $layer)->value('body') ?? self::DEFAULTS[$layer] ?? '');
    }

    /**
     * The title filter's rules (UI "Exclude keywords"): one per line, words separated by semicolons all required.
     *
     * @return list<list<string>> each rule's words
     */
    public static function excludeKeywords(): array
    {
        $rules = [];

        // Each non-empty line becomes a rule of its words.
        foreach (preg_split('/\R/u', self::bodyFor('title_filter')) ?: [] as $line) {
            $words = array_values(array_filter(array_map(trim(...), preg_split('/[;；]/u', $line) ?: []), fn (string $word): bool => $word !== ''));

            if ($words !== []) {
                $rules[] = $words;
            }
        }

        return $rules;
    }

    /**
     * The first rule whose words are all in the title, as "word; word", or null.
     *
     * @param  list<list<string>>|null  $rules
     */
    public static function excludedBy(string $title, ?array $rules = null): ?string
    {
        // First rule that matches every word wins.
        foreach ($rules ?? self::excludeKeywords() as $words) {
            if (array_all($words, fn (string $word): bool => preg_match(self::wordPattern($word), $title) === 1)) {
                return implode('; ', $words);
            }
        }

        return null;
    }

    /** A keyword as a whole-word regex; a trailing * allows a suffix, and CJK ends get no boundary. */
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
