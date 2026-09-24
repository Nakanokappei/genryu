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
 * 文書 (UI "Documents"): an entry of a source's update list, kept as the
 * original file and its Markdown. Status null, then fetching / fetched /
 * failed (UI 取得中 / 取得済み / 失敗); excluded_by is the title filter rule
 * that made it 対象外; human_decision is 人の判定.
 *
 * @property float|null $likeness らしさ: nearest like minus nearest unlike
 * @property array<string, mixed>|null $likeness_detail the nearest like and unlike, their similarities, the model
 * @property CarbonImmutable|null $published_at
 * @property bool $published_has_time
 * @property string|null $language 言語, one of App\Enums\Language
 * @property CarbonImmutable|null $human_decided_at
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /** feed: the feed's summary, kept until the full text is fetched. */
    public const FORMATS = ['html', 'pdf', 'feed'];

    /** Below this many characters a body is 本文が短い. */
    public const SHORT_BODY_CHARS = 1000;

    protected $fillable = ['source_id', 'title', 'url', 'published_at', 'published_has_time', 'excluded_by', 'likeness', 'likeness_detail', 'format', 'language', 'original_path', 'markdown', 'fetched_at', 'status', 'status_message', 'latest_screening_id', 'human_decision', 'human_reason', 'human_decided_at', 'human_decided_by'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'published_has_time' => 'boolean', 'likeness' => 'float', 'likeness_detail' => 'array', 'fetched_at' => 'datetime', 'human_decided_at' => 'datetime'];
    }

    /** 公開日時 in the display timezone, or 公開日 as written (a day is midnight UTC, never shifted). */
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

    /** @return BelongsTo<Screening, $this> the latest screening */
    public function latestScreening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'latest_screening_id');
    }

    /** @return HasMany<Screening, $this> every screening of the document, latest first */
    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class)->latest('id');
    }

    /** 本文が短い: a fetched, non-excluded, non-feed body under SHORT_BODY_CHARS. */
    public function hasShortBody(): bool
    {
        return $this->status === 'fetched' && $this->excluded_by === null && $this->format !== 'feed' && mb_strlen((string) $this->markdown) < self::SHORT_BODY_CHARS;
    }

    /** Whether an adopted feed summary is due its full-text fetch. */
    public function wantsFullText(): bool
    {
        return $this->format === 'feed' && $this->status === 'fetched' && $this->excluded_by === null && ! $this->isLeftOut() && $this->decision() === 'adopt';
    }

    /** Whether the semantic filter left it out: measured and below the threshold (unmeasured passes). */
    public function isLeftOut(): bool
    {
        return $this->likeness !== null && $this->likeness < EditorialPolicy::likenessThreshold();
    }

    /** @return HasOne<DocumentEmbedding, $this> */
    public function embedding(): HasOne
    {
        return $this->hasOne(DocumentEmbedding::class);
    }

    /** @return HasOne<SpotCheck, $this> its 抜き取り点検 draw, if drawn */
    public function spotCheck(): HasOne
    {
        return $this->hasOne(SpotCheck::class);
    }

    /** @return HasOne<SemanticFilterExample, $this> its semantic filter example, if marked */
    public function semanticFilterExample(): HasOne
    {
        return $this->hasOne(SemanticFilterExample::class);
    }

    /** Detects the language once on save and records every new Markdown as a revision. */
    protected static function booted(): void
    {
        static::saving(function (Document $document): void {
            // Detect the language only while it is unset.
            if ($document->language === null && $document->markdown !== null && $document->markdown !== '') {
                $document->language = DetectLanguage::of($document->title."\n".$document->markdown);
            }
        });

        static::saved(function (Document $document): void {
            // Record a revision when the Markdown is new or changed.
            if (($document->wasRecentlyCreated || $document->wasChanged('markdown')) && $document->markdown !== null && $document->markdown !== '') {
                $document->recordRevision();
            }
        });
    }

    /** The current Markdown as a revision: the latest one if unchanged, else a new one. */
    public function recordRevision(): ?DocumentRevision
    {
        $markdown = (string) $this->markdown;

        // Nothing to record.
        if ($markdown === '') {
            return null;
        }

        $latest = $this->revisions()->latest('id')->first();

        // Reuse the latest revision when the text is the same.
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

    /** @return BelongsTo<User, $this> who recorded 人の判定 */
    public function humanDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'human_decided_by');
    }

    /** 判定: 人の判定 if any, else the latest screening's decision. */
    public function decision(): ?string
    {
        return $this->human_decision ?? ($this->latestScreening?->status === 'screened' ? $this->latestScreening->decision : null);
    }

    /** Whether the standing decision is reject (the gate before extraction). */
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
     * Documents the semantic filter lets through (see isLeftOut()).
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
