<?php

namespace App\Models;

use App\Enums\Language;
use Carbon\CarbonImmutable;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * 記事 (UI: "Articles"): written from a material by App\Jobs\GenerateArticle
 * in the language of its primary source, then translated into the other
 * languages we publish in by App\Jobs\TranslateArticle — a translation of
 * the article, never the same piece written again from the material, so
 * that what the reporter found in the source survives into every language.
 * A material therefore has several articles: the original, whose
 * translated_from_id is null, and its translations, each pointing at it.
 * Status generating / written / failed (UI: 生成中 / 作成済み / 失敗). Pinned, like a screening and a material, to the
 * prompt version and the model it was written with, with the usage of
 * the call, so articles written under different policies can be compared.
 *
 * @property list<array{url: string, alt: string, caption: ?string, section: string}>|null $figures 図版: the source's figures quoted, by URL, each with its section
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
     * Where a quoted figure can stand (UI 図版): at the end of the opening
     * (before the first ## heading), or of the first, second or third ##
     * section — the background, the new technology, the world once it is
     * real.
     */
    public const FIGURE_SECTIONS = ['opening', 'background', 'technology', 'outlook'];

    /**
     * The line between the lead and the rest of the body (a rule of our
     * own Markdown, 2026-09-25): the lead is written after the body, as a
     * summary of it (the writer's `lead`, App\Jobs\GenerateArticle), and
     * put above it with this line between them, so
     * whatever shows the article knows whether there is a lead and where
     * the body begins. Five hyphens, a thematic break to any other reader.
     */
    public const LEAD_SEPARATOR = '-----';

    /** At most this many figures are quoted in one article, so the article stays the main thing and the figures serve it. */
    public const MAX_FIGURES = 2;

    protected $fillable = [
        'material_id', 'language', 'translated_from_id', 'prompt_id', 'model', 'headline', 'body', 'figures', 'status', 'status_message', 'published_at', 'scheduled_at', 'image_path', 'image_time',
        'headline_prompt_id', 'headline_model', 'headline_review',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'scheduled_at' => 'datetime', 'estimated_total_cost' => 'float', 'headline_review' => 'array', 'figures' => 'array'];
    }

    /** What the screens call the article: its headline once written, the document's title until then. */
    public function displayHeadline(): string
    {
        return $this->headline ?? (string) $this->material?->document->title;
    }

    /** What the screens call its language. */
    public function languageName(): string
    {
        return Language::nameOf($this->language);
    }

    /**
     * The figures of the primary source this article quotes, each with
     * the section it stands in: the original's, for a translation too.
     *
     * @return list<array{url: string, alt: string, caption: ?string, section: string}>
     */
    public function quotedFigures(): array
    {
        return $this->original()->figures ?? [];
    }

    /**
     * The body as HTML, with the quoted figures set in after the section
     * each stands in. A figure is a quotation, never a copy: the image is
     * the source's own URL, loaded by the reader's browser (no referrer
     * sent, gone from the page if the source will not serve it), shown no
     * larger than the article can carry and never cropped, apart from
     * the text in a frame of its own, with the source's caption as it
     * was and the source named and linked in the article's language.
     */
    public function bodyHtml(): string
    {
        [$lead, $body] = $this->leadAndBody();
        // The lead on its own, so the page can set it apart from the body that follows.
        $html = $lead !== null ? '<div data-lead>'.Str::markdown($lead, ['html_input' => 'strip', 'allow_unsafe_links' => false]).'</div>' : '';
        // The body in its sections: the opening before the first ## heading, then one section per heading.
        $sections = preg_split('/^(?=## )/m', $body) ?: [''];
        $figures = $this->quotedFigures();
        $html .= '<div data-body>';

        foreach ($sections as $index => $section) {
            $html .= Str::markdown($section, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

            foreach ($figures as $figure) {
                // A figure whose section the body does not have stands after the opening.
                $at = array_search($figure['section'], self::FIGURE_SECTIONS, true);
                $at = is_int($at) && $at < count($sections) ? $at : 0;

                if ($at === $index) {
                    $html .= $this->figureHtml($figure);
                }
            }
        }

        return $html.'</div>';
    }

    /**
     * The body without the headline some translators put at its head as a
     * # line: the headline is shown above the body.
     */
    public function bodyWithoutHeadline(): string
    {
        return (string) preg_replace('/\A\s*#\s[^\n]*\n*/u', '', (string) $this->body);
    }

    /** A lead and a body as the article keeps them: the lead, the separator line, the body. */
    public static function withLead(string $lead, string $body): string
    {
        return trim($lead)."\n\n".self::LEAD_SEPARATOR."\n\n".trim($body);
    }

    /**
     * The lead and the rest of the body, split at the separator line; no
     * lead when there is no separator.
     *
     * @return array{0: ?string, 1: string}
     */
    public function leadAndBody(): array
    {
        $parts = preg_split('/^'.preg_quote(self::LEAD_SEPARATOR, '/').'\s*$/m', $this->bodyWithoutHeadline(), 2) ?: [''];

        return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : [null, trim($parts[0])];
    }

    /**
     * One quoted figure.
     *
     * @param  array{url: string, alt: string, caption: ?string, section: string}  $figure
     */
    private function figureHtml(array $figure): string
    {
        $document = $this->material?->document;
        $label = (Language::tryFrom((string) $this->language) ?? Language::English)->sourceLabel();
        $caption = trim((string) ($figure['caption'] ?? ''));
        $source = $document !== null
            ? e($label).': <a href="'.e($document->url).'" target="_blank" rel="noopener noreferrer">'.e($document->title).'</a>（'.e($document->source->name).'）'
            : '';

        return '<figure data-quotation style="margin:1.5rem 0;padding:0.75rem;border:1px solid rgba(128,128,128,0.35);border-radius:0.5rem">'
            .'<img src="'.e($figure['url']).'" alt="'.e($figure['alt']).'" loading="lazy" decoding="async" referrerpolicy="no-referrer"'
            .' style="display:block;margin:0 auto;max-width:100%;max-height:400px;width:auto;height:auto;object-fit:contain"'
            .' onerror="this.closest(\'figure\').remove()">'
            .'<figcaption style="margin-top:0.5rem;font-size:0.75rem;opacity:0.75">'.($caption !== '' ? e($caption).' ' : '').$source.'</figcaption>'
            .'</figure>';
    }

    /** The article as written: this one, or the one it was translated from (null when that is gone). */
    public function original(): ?Article
    {
        return $this->isOriginal() ? $this : $this->translatedFrom;
    }

    /**
     * Articles as written, not translations.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function originals(Builder $query): void
    {
        $query->whereNull('translated_from_id');
    }

    /** Whether this is the article as written, rather than a translation of one. */
    public function isOriginal(): bool
    {
        return $this->translated_from_id === null;
    }

    /**
     * Whether this language version is to be published, by the language
     * settings: a translation when its language takes every source, the
     * original when its language takes its own sources too. An original
     * that is not is the working copy its translations are made from.
     */
    public function isPublishable(): bool
    {
        return LanguageSetting::publishes((string) $this->language, $this->original()?->language);
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
        return Language::tryFrom((string) $this->language)?->timezone() ?? 'UTC';
    }

    /** When this language version is to be published, in its own zone (公開予定日時), or null when it is not scheduled. */
    public function scheduledLocal(): ?CarbonImmutable
    {
        return $this->scheduled_at?->setTimezone($this->timezone());
    }

    /** The scheduled local time as the screens show it, with the weekday. */
    public function scheduledLocalDisplay(): ?string
    {
        return $this->scheduledLocal()?->settings(['locale' => app()->getLocale()])->isoFormat('YYYY-MM-DD（ddd） HH:mm');
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
