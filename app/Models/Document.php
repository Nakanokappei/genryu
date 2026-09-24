<?php

namespace App\Models;

use App\Actions\DetectLanguage;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 文書 (UI: "Documents"): a document found on a source's update list
 * (stage 2.1 of docs/HANDOVER.md) and fetched from the source (stage 2.2):
 * the HTML or PDF kept as the original file and read into Markdown, by
 * App\Jobs\FetchDocument in the background. Status null until a fetch is
 * queued, then fetching / fetched / failed (UI: 取得中 / 取得済み / 失敗).
 * A document whose title has an exclude keyword of the editorial policy is
 * listed as 対象外 (excluded_by) and not fetched. A fetched document is
 * screened by App\Jobs\ScreenDocument (UI: スクリーニング); the latest
 * screening (latest_screening_id) carries the decision 採用 / 不採用 / 要確認. A
 * person may record their own verdict (UI: 人の判定, human_decision adopt /
 * reject with a reason), which outranks the screening's at the gate.
 *
 * @property float|null $likeness らしさ: how much nearer the nearest "like" of the semantic filter is than the nearest "unlike" (App\Actions\MeasureLikeness)
 * @property array<string, mixed>|null $likeness_detail what the likeness was measured against: the nearest like and unlike, their similarities, the model
 * @property CarbonImmutable|null $published_at
 * @property bool $published_has_time
 * @property string|null $language the language the document is written in (言語), one of App\Enums\Language
 * @property CarbonImmutable|null $human_decided_at
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /** feed: the summary the feed gave (UI 全文へのリンク on the source), kept until the document is adopted and its full text fetched. */
    public const FORMATS = ['html', 'pdf', 'feed'];

    /** A fetched body shorter than this (UI: 本文が短い) is probably a teaser: the source's document settings may miss the body. */
    public const SHORT_BODY_CHARS = 1000;

    protected $fillable = ['source_id', 'title', 'url', 'published_at', 'published_has_time', 'excluded_by', 'likeness', 'likeness_detail', 'format', 'language', 'original_path', 'markdown', 'fetched_at', 'status', 'status_message', 'latest_screening_id', 'human_decision', 'human_reason', 'human_decided_at', 'human_decided_by'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'published_has_time' => 'boolean', 'likeness' => 'float', 'likeness_detail' => 'array', 'fetched_at' => 'datetime', 'human_decided_at' => 'datetime'];
    }

    /**
     * 公開日時 / 公開日: when the source dated the document to the minute,
     * the instant in the display timezone; when it gave only a day, that
     * day as it was written (the value is midnight UTC and must not be
     * moved to another timezone, which would show the day before or after).
     */
    public function publishedDisplay(): ?string
    {
        return match (true) {
            $this->published_at === null => null,
            $this->published_has_time => $this->published_at->display(),
            default => $this->published_at->format('Y-m-d'),
        };
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return HasOne<Material, $this> */
    public function material(): HasOne
    {
        return $this->hasOne(Material::class);
    }

    /** @return BelongsTo<Screening, $this> the latest screening of the document */
    public function latestScreening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'latest_screening_id');
    }

    /** @return HasMany<Screening, $this> every screening of the document, latest first */
    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class)->latest('id');
    }

    /**
     * Whether the fetched body is suspiciously short: the settings of the
     * source caught a teaser, a header or a page whose body sits elsewhere.
     * An excluded document is not worth the warning, nor a summary from
     * the feed, which is short by nature.
     */
    public function hasShortBody(): bool
    {
        return $this->status === 'fetched' && $this->excluded_by === null && $this->format !== 'feed' && mb_strlen((string) $this->markdown) < self::SHORT_BODY_CHARS;
    }

    /**
     * Whether the summary from the feed is to be replaced by the full
     * text now: the document was adopted (by the screening or a person),
     * the only point at which the full text is worth its fetch.
     */
    public function wantsFullText(): bool
    {
        return $this->format === 'feed' && $this->status === 'fetched' && $this->excluded_by === null && ! $this->isLeftOut() && $this->decision() === 'adopt';
    }

    /**
     * Whether the semantic filter leaves the document out (UI 対象外):
     * its likeness was measured and falls below the threshold set on
     * 文書. A document not measured yet is not held back.
     */
    public function isLeftOut(): bool
    {
        return $this->likeness !== null && $this->likeness < EditorialPolicy::likenessThreshold();
    }

    /** @return HasOne<DocumentEmbedding, $this> */
    public function embedding(): HasOne
    {
        return $this->hasOne(DocumentEmbedding::class);
    }

    /** @return HasOne<SpotCheck, $this> the document drawn for 抜き取り点検, when it was */
    public function spotCheck(): HasOne
    {
        return $this->hasOne(SpotCheck::class);
    }

    /** @return HasOne<SemanticFilterExample, $this> the document as an example of the semantic filter, when a person made it one */
    public function semanticFilterExample(): HasOne
    {
        return $this->hasOne(SemanticFilterExample::class);
    }

    /**
     * Every Markdown written to the document is kept as a revision, so
     * what a screening or a material was made from stays as it was. The
     * language is guessed the first time there is text to guess it from
     * (App\Actions\DetectLanguage); once set, by the guess or by a
     * person, it is left alone.
     */
    protected static function booted(): void
    {
        static::saving(function (Document $document): void {
            if ($document->language === null && $document->markdown !== null && $document->markdown !== '') {
                $document->language = DetectLanguage::of($document->title."\n".$document->markdown);
            }
        });

        static::saved(function (Document $document): void {
            if (($document->wasRecentlyCreated || $document->wasChanged('markdown')) && $document->markdown !== null && $document->markdown !== '') {
                $document->recordRevision();
            }
        });
    }

    /**
     * The document's Markdown as a revision: the latest one when the
     * text is unchanged, else a new one.
     */
    public function recordRevision(): ?DocumentRevision
    {
        $markdown = (string) $this->markdown;

        if ($markdown === '') {
            return null;
        }

        $latest = $this->revisions()->latest('id')->first();

        if ($latest !== null && $latest->sha256 === hash('sha256', $markdown)) {
            return $latest;
        }

        return $this->revisions()->create(['markdown' => $markdown, 'sha256' => hash('sha256', $markdown), 'chars' => mb_strlen($markdown)]);
    }

    /** @return HasMany<DocumentRevision, $this> every Markdown the document had, oldest first */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class);
    }

    /** @return BelongsTo<User, $this> who recorded the human decision */
    public function humanDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'human_decided_by');
    }

    /**
     * The decision that stands (UI 判定): a person's when there is one,
     * else the latest screening's; null when neither has decided.
     */
    public function decision(): ?string
    {
        return $this->human_decision ?? ($this->latestScreening?->status === 'screened' ? $this->latestScreening->decision : null);
    }

    /**
     * Whether the gate lets the document on to the detailed analysis: a
     * rejected document does not go; one not screened yet, or to be
     * reviewed, is not stopped here.
     */
    public function isRejected(): bool
    {
        return $this->decision() === 'reject';
    }

    /**
     * Documents whose standing decision (see decision()) is the one given.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function decidedAs(Builder $query, string $decision): void
    {
        $query->where(fn (Builder $query) => $query->where('human_decision', $decision)
            ->orWhere(fn (Builder $query) => $query->whereNull('human_decision')->whereRelation('latestScreening', fn (Builder $screening) => $screening->where('status', 'screened')->where('decision', $decision))));
    }

    /**
     * Documents with no standing decision (decision() is null).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function undecided(Builder $query): void
    {
        $query->whereNull('human_decision')->whereDoesntHave('latestScreening', fn (Builder $screening) => $screening->where('status', 'screened')->whereNotNull('decision'));
    }

    /**
     * Documents the semantic filter left out (see isLeftOut()).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function leftOut(Builder $query): void
    {
        $query->where('likeness', '<', EditorialPolicy::likenessThreshold());
    }

    /**
     * Documents the semantic filter lets through: not measured yet, or at or above the threshold.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notLeftOut(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('likeness')->orWhere('likeness', '>=', EditorialPolicy::likenessThreshold()));
    }

    /**
     * Documents with a short body (see hasShortBody()).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withShortBody(Builder $query): void
    {
        $query->where('status', 'fetched')->whereNull('excluded_by')->where('format', '!=', 'feed')
            ->whereRaw('coalesce(length(markdown), 0) < ?', [self::SHORT_BODY_CHARS]);
    }
}
