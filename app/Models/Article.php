<?php

namespace App\Models;

use App\Crawl\Url;
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
 * 記事 (UI "Articles"): an original written from a material in its source's
 * language (translated_from_id null), or a translation of one.
 * Status generating / written / failed (UI 生成中 / 作成済み / 失敗).
 *
 * @property list<array{url: string, alt: string, caption: ?string, section: string}>|null $figures 図版: the quoted source figures, each with its section
 * @property string|null $language
 * @property array<string, mixed>|null $headline_review the headline's scores, with every attempt
 * @property CarbonImmutable|null $scheduled_at 公開予定日時, in UTC
 * @property CarbonImmutable|null $published_at 公開日時, in UTC
 * @property string|null $image_path トップ画像 on the local disk
 * @property string|null $image_time the local time of day the top image was drawn for
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    /** Sets every line of a body apart by a blank line, so each is a Markdown block of its own. */
    public static function separateBlocks(string $body): string
    {
        return trim(preg_replace('/\n\s*\n+|\n/u', "\n\n", str_replace("\r\n", "\n", $body)) ?? $body);
    }

    /** Where a quoted figure (図版) can stand: after the opening or after one of the three ## sections. */
    public const FIGURE_SECTIONS = ['opening', 'background', 'technology', 'outlook'];

    /** The line between the lead and the body. */
    public const LEAD_SEPARATOR = '-----';

    /** The most figures one article quotes. */
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

    /** The headline, or the document's title until there is one. */
    public function displayHeadline(): string
    {
        return $this->headline ?? (string) $this->material?->document->title;
    }

    /** The language's display name. */
    public function languageName(): string
    {
        return Language::nameOf($this->language);
    }

    /**
     * The quoted figures (the original's, for a translation).
     *
     * @return list<array{url: string, alt: string, caption: ?string, section: string}>
     */
    public function quotedFigures(): array
    {
        return $this->original()->figures ?? [];
    }

    /** The lead and body as HTML, each figure set in after its section. */
    public function bodyHtml(): string
    {
        [$lead, $body] = $this->leadAndBody();
        // Lead in its own block.
        $html = $lead !== null ? '<div data-lead>'.Str::markdown($lead, ['html_input' => 'strip', 'allow_unsafe_links' => false]).'</div>' : '';
        // Split into the opening and one section per ## heading.
        $sections = preg_split('/^(?=## )/m', $body) ?: [''];
        $figures = $this->quotedFigures();
        $html .= '<div data-body>';

        // Render each section, then the figures that belong after it.
        foreach ($sections as $index => $section) {
            $html .= Str::markdown($section, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

            foreach ($figures as $figure) {
                // A figure for a missing section goes after the opening.
                $at = array_search($figure['section'], self::FIGURE_SECTIONS, true);
                $at = is_int($at) && $at < count($sections) ? $at : 0;

                // Only an http(s) image is put into the page.
                if ($at === $index && Url::isWeb($figure['url'])) {
                    $html .= $this->figureHtml($figure);
                }
            }
        }

        return $html.'</div>';
    }

    /** The body without a leading # headline line. */
    public function bodyWithoutHeadline(): string
    {
        return (string) preg_replace('/\A\s*#\s[^\n]*\n*/u', '', (string) $this->body);
    }

    /** Joins a lead and a body with the separator line. */
    public static function withLead(string $lead, string $body): string
    {
        return trim($lead)."\n\n".self::LEAD_SEPARATOR."\n\n".trim($body);
    }

    /**
     * The lead and the body, split at the separator line (no lead without one).
     *
     * @return array{0: ?string, 1: string}
     */
    public function leadAndBody(): array
    {
        $parts = preg_split('/^'.preg_quote(self::LEAD_SEPARATOR, '/').'\s*$/m', $this->bodyWithoutHeadline(), 2) ?: [''];

        return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : [null, trim($parts[0])];
    }

    /**
     * One quoted figure as HTML: the source's URL, caption and 出典 link.
     *
     * @param  array{url: string, alt: string, caption: ?string, section: string}  $figure
     */
    private function figureHtml(array $figure): string
    {
        $document = $this->material?->document;
        $label = (Language::tryFrom((string) $this->language) ?? Language::English)->sourceLabel();
        $caption = trim((string) ($figure['caption'] ?? ''));
        $source = $document !== null && Url::isWeb($document->url)
            ? e($label).': <a href="'.e($document->url).'" target="_blank" rel="noopener noreferrer">'.e($document->title).'</a>（'.e($document->source->nameIn($this->language)).'）'
            : '';

        return '<figure data-quotation style="margin:1.5rem 0;padding:0.75rem;border:1px solid rgba(128,128,128,0.35);border-radius:0.5rem">'
            .'<img src="'.e($figure['url']).'" alt="'.e($figure['alt']).'" loading="lazy" decoding="async" referrerpolicy="no-referrer"'
            .' style="display:block;margin:0 auto;max-width:100%;max-height:400px;width:auto;height:auto;object-fit:contain"'
            .' onerror="this.closest(\'figure\').remove()">'
            .'<figcaption style="margin-top:0.5rem;font-size:0.75rem;opacity:0.75">'.($caption !== '' ? e($caption).' ' : '').$source.'</figcaption>'
            .'</figure>';
    }

    /** This article, or the one it was translated from. */
    public function original(): ?Article
    {
        return $this->isOriginal() ? $this : $this->translatedFrom;
    }

    /**
     * Originals only, no translations.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function originals(Builder $query): void
    {
        $query->whereNull('translated_from_id');
    }

    /** Whether this is an original rather than a translation. */
    public function isOriginal(): bool
    {
        return $this->translated_from_id === null;
    }

    /** Whether 言語設定 publishes this language version (else it is a working copy). */
    public function isPublishable(): bool
    {
        return LanguageSetting::publishes((string) $this->language, $this->original()?->language);
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return BelongsTo<Prompt, $this> the prompt version it was written or translated with */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /** @return BelongsTo<Prompt, $this> the headline prompt version */
    public function headlinePrompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'headline_prompt_id');
    }

    /** @return BelongsTo<Article, $this> the article this one was translated from */
    public function translatedFrom(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'translated_from_id');
    }

    /** 公開日時未定 → 画像作成中 → スケジュール済み → 公開済み (UI 編成 › 記事), derived from the columns. */
    public function publicationStatus(): string
    {
        return match (true) {
            $this->published_at !== null => 'published',
            $this->scheduled_at === null => 'unscheduled',
            $this->image_path === null || $this->image_time !== $this->scheduledLocal()?->format('H:i') => 'imaging',
            default => 'scheduled',
        };
    }

    /** The timezone this language version is published in. */
    public function timezone(): string
    {
        return Language::tryFrom((string) $this->language)?->timezone() ?? 'UTC';
    }

    /** 公開予定日時 in the language's own timezone. */
    public function scheduledLocal(): ?CarbonImmutable
    {
        return $this->scheduled_at?->setTimezone($this->timezone());
    }

    /** The scheduled local time for display, with the weekday. */
    public function scheduledLocalDisplay(): ?string
    {
        return $this->scheduledLocal()?->settings(['locale' => app()->getLocale()])->isoFormat('YYYY-MM-DD（ddd） HH:mm');
    }

    /** @return HasMany<ArticleImage, $this> every drawing of the top image */
    public function images(): HasMany
    {
        return $this->hasMany(ArticleImage::class);
    }

    /** @return HasOne<ArticleImage, $this> the latest drawing */
    public function image(): HasOne
    {
        return $this->hasOne(ArticleImage::class)->latestOfMany();
    }

    /** @return HasMany<QualityCheck, $this> every 品質チェック of the article */
    public function qualityChecks(): HasMany
    {
        return $this->hasMany(QualityCheck::class);
    }

    /** @return HasOne<QualityCheck, $this> the latest 品質チェック */
    public function qualityCheck(): HasOne
    {
        return $this->hasOne(QualityCheck::class)->latestOfMany();
    }

    /** @return HasMany<Article, $this> its translations */
    public function translations(): HasMany
    {
        return $this->hasMany(Article::class, 'translated_from_id');
    }
}
