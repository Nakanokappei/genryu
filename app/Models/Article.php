<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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
 * @property CarbonImmutable|null $scheduled_at when this language version is to be published (公開予定日時), stored in UTC
 * @property string|null $image_path the top image (トップ画像) on the local disk, once it is made
 * @property string|null $image_time the local time of day the top image was made for, whose band gave it its style
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    public const STATUSES = ['generating', 'draft', 'failed', 'published'];

    /**
     * The languages we publish in (decided 2026-09-23), in the order of
     * the countries' R&D spending in the UNESCO figures for 2023. Which
     * sources get an article in each is set on 記事 (LanguageSetting); an
     * article is written in its source's language and translated from
     * there, never into its own.
     */
    public const LANGUAGES = ['en', 'zh-Hant', 'ja', 'de', 'ko', 'fr', 'zh-Hans'];

    /** The languages a primary source may be in, which an original article may therefore be written in. */
    public const SOURCE_LANGUAGES = self::LANGUAGES;

    /** What each language is called on the screens. */
    public const LANGUAGE_NAMES = [
        'en' => 'English',
        'zh-Hant' => '繁體中文',
        'ja' => '日本語',
        'de' => 'Deutsch',
        'ko' => '한국어',
        'fr' => 'Français',
        'zh-Hans' => '简体中文',
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

    /**
     * Where each language is read, for the time it is published at (公開予定日時):
     * English by New York, every other language by its country's capital
     * (decided 2026-09-23). Every language version of an article goes out
     * at the same local date and time of day, each in its own zone.
     */
    public const TIMEZONES = [
        'ja' => 'Asia/Tokyo',
        'en' => 'America/New_York',
        'zh-Hans' => 'Asia/Shanghai',
        'zh-Hant' => 'Asia/Taipei',
        'de' => 'Europe/Berlin',
        'ko' => 'Asia/Seoul',
        'fr' => 'Europe/Paris',
    ];

    protected $fillable = [
        'material_id', 'language', 'translated_from_id', 'prompt_id', 'model', 'title', 'body', 'status', 'status_message', 'published_at', 'scheduled_at', 'image_path', 'image_time',
        'headline_prompt_id', 'headline_model', 'headline_review',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'scheduled_at' => 'datetime', 'estimated_total_cost' => 'float', 'headline_review' => 'array'];
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
     * that take every source (言語設定), less the one it is written in.
     *
     * @return list<string>
     */
    public function translationLanguages(): array
    {
        return LanguageSetting::translationTargets($this->language);
    }

    /**
     * Whether this language version is to be published, by the language
     * settings: a translation when its language takes every source, the
     * original when its language takes its own sources too. An original
     * that is not is the working copy its translations are made from.
     */
    public function isPublishable(): bool
    {
        return LanguageSetting::publishes((string) $this->language, $this->isOriginal() ? $this->language : $this->translatedFrom?->language);
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

    /**
     * Where an article stands on its way out (UI 編成 › 記事), in the order
     * it moves: 公開日時未定 until the schedule gives it a time, 画像作成中
     * until its top image is made for that time of day (a slot moved to
     * another hour needs another image), スケジュール済み once both are in place,
     * 公開済み once it is out. Worked out from the article, not kept, so it
     * cannot disagree with what the article holds.
     */
    public function publicationStatus(): string
    {
        return match (true) {
            $this->published_at !== null => 'published',
            $this->scheduled_at === null => 'unscheduled',
            $this->image_path === null || $this->image_time !== $this->scheduledLocal()?->format('H:i') => 'imaging',
            default => 'scheduled',
        };
    }

    /** The zone this language version is read in, by which its publication time is set. */
    public function timezone(): string
    {
        return self::TIMEZONES[$this->language] ?? 'UTC';
    }

    /** When this language version is to be published, in its own zone (公開予定日時), or null when it is not scheduled. */
    public function scheduledLocal(): ?CarbonImmutable
    {
        return $this->scheduled_at?->setTimezone($this->timezone());
    }

    /** @return HasMany<ArticleImage, $this> every drawing of this article's top image */
    public function images(): HasMany
    {
        return $this->hasMany(ArticleImage::class);
    }

    /** @return HasOne<ArticleImage, $this> the latest drawing, the one the screens show */
    public function image(): HasOne
    {
        return $this->hasOne(ArticleImage::class)->latestOfMany();
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
