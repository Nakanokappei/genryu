<?php

namespace App\Models;

use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 記事 (UI: "Articles"): written from a material by App\Jobs\GenerateArticle
 * in the language of its primary source, then translated into the other
 * languages we publish in by App\Jobs\TranslateArticle — a translation of
 * the article, never the same piece written again from the material, so
 * that what the reporter found in the source survives into every language.
 * A material therefore has several articles: the original, whose
 * translated_from_id is null, and its translations, each pointing at it.
 * Status generating / draft / failed / published (UI: 生成中 / 下書き /
 * 失敗 / 公開済み). Pinned, like a screening and a material, to the
 * prompt version and the model it was written with, with the usage of
 * the call, so articles written under different policies can be compared.
 *
 * @property string|null $language
 * @property array<string, mixed>|null $headline_review what the headline scored against the rubric, with every attempt
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    public const STATUSES = ['generating', 'draft', 'failed', 'published'];

    /**
     * The languages every article is published in, whatever it was
     * written in (docs/HANDOVER.md §3). A source in another language
     * keeps its own article as well; a source in one of these is not
     * translated into itself.
     */
    public const LANGUAGES = ['ja', 'en', 'zh-Hant', 'zh-Hans'];

    /** The languages a primary source may be in, which an original article may therefore be written in. */
    public const SOURCE_LANGUAGES = ['ja', 'en', 'de', 'fr', 'zh-Hans', 'zh-Hant'];

    /** What each language is called on the screens. */
    public const LANGUAGE_NAMES = [
        'ja' => '日本語',
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'zh-Hans' => '简体中文',
        'zh-Hant' => '繁體中文',
    ];

    /**
     * A body with a blank line between every two lines: models write the
     * paragraphs one newline apart, which Markdown runs together into one
     * paragraph (the lead into the opening). Every line of a body is a
     * block of its own — a paragraph, a heading, the source's link — so
     * nothing is lost by setting them apart.
     */
    public static function separateBlocks(string $body): string
    {
        return trim(preg_replace('/\n\s*\n+|\n/u', "\n\n", str_replace("\r\n", "\n", $body)) ?? $body);
    }

    protected $fillable = [
        'material_id', 'language', 'translated_from_id', 'prompt_id', 'model', 'title', 'body', 'status', 'status_message', 'published_at',
        'headline_prompt_id', 'headline_model', 'headline_review',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'estimated_total_cost' => 'float', 'headline_review' => 'array'];
    }

    /**
     * What the screens call the article: its title once written, the
     * document's title until then.
     */
    public function displayTitle(): string
    {
        return $this->title ?? (string) $this->material?->document->title;
    }

    /** What the screens call its language. */
    public function languageName(): string
    {
        return self::LANGUAGE_NAMES[$this->language] ?? (string) $this->language;
    }

    /** Whether this is the article as written, rather than a translation of one. */
    public function isOriginal(): bool
    {
        return $this->translated_from_id === null;
    }

    /**
     * The languages this article is still to be translated into: the ones
     * we publish in, less the one it is written in.
     *
     * @return list<string>
     */
    public function translationLanguages(): array
    {
        return array_values(array_diff(self::LANGUAGES, [(string) $this->language]));
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return BelongsTo<Prompt, $this> the version of the layer it was written or translated with */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /** @return BelongsTo<Prompt, $this> the version of the headline layer the loop scored it with */
    public function headlinePrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'headline_prompt_id');
    }

    /** @return BelongsTo<Article, $this> the article this one was translated from */
    public function translatedFrom(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'translated_from_id');
    }

    /** @return HasMany<QualityCheck, $this> every scoring of this article (品質チェック) */
    public function qualityChecks(): HasMany
    {
        return $this->hasMany(QualityCheck::class);
    }

    /** @return HasOne<QualityCheck, $this> the latest scoring, the one the screens show */
    public function qualityCheck(): HasOne
    {
        return $this->hasOne(QualityCheck::class)->latestOfMany();
    }

    /** @return HasMany<Article, $this> the translations made from this article */
    public function translations(): HasMany
    {
        return $this->hasMany(Article::class, 'translated_from_id');
    }
}
