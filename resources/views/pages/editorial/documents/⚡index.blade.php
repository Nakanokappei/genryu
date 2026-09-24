<?php

use App\Actions\MeasureLikeness;
use App\Actions\ScreeningFigures;
use App\Jobs\ApplySemanticFilter;
use App\Jobs\ScreenDocument;
use App\Livewire\PagedList;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\Models\SemanticFilterExample;
use App\Models\Screening;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

// 文書 (Documents): the semantic filter, the content filtering and the list of documents.
new #[Title('文書')] class extends PagedList {
    /** UI: 埋め込みモデル */
    public string $semanticFilterModel = EditorialPolicy::DEFAULT_EMBEDDING_MODEL;

    /** UI: 閾値 (likeness) */
    public string $semanticFilterThreshold = '';

    // The developer prompt of the screening (UI: コンテンツフィルタリング).
    public string $contentFiltering = '';

    /** UI: 初回判定モデル */
    public string $contentFilteringModel = EditorialPolicy::DEFAULT_MODEL;

    // Load the policy settings.
    public function mount(): void
    {
        $filter = EditorialPolicy::semanticFilter();
        $this->semanticFilterModel = $filter['model'];
        $this->semanticFilterThreshold = sprintf('%.2f', $filter['threshold']);
        $this->contentFiltering = EditorialPolicy::bodyFor('content_filtering');
        $this->contentFilteringModel = EditorialPolicy::modelFor('content_filtering');
    }

    // Save the content filtering prompt and model.
    public function saveContentFiltering(): void
    {
        $this->validate(['contentFilteringModel' => EditorialPolicy::modelRule(EditorialPolicy::screeningModels())]);
        EditorialPolicy::query()->updateOrCreate(['layer' => 'content_filtering'], ['body' => $this->contentFiltering, 'model' => $this->contentFilteringModel]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Save the model and threshold, and measure the embedded documents again.
    public function saveSemanticFilter(MeasureLikeness $measure): void
    {
        $this->validate([
            'semanticFilterModel' => EditorialPolicy::modelRule(EditorialPolicy::EMBEDDING_MODELS),
            'semanticFilterThreshold' => ['required', 'numeric', 'between:-1,1'],
        ]);
        EditorialPolicy::query()->updateOrCreate(['layer' => 'semantic_filter'], ['body' => '', 'model' => $this->semanticFilterModel, 'threshold' => (float) $this->semanticFilterThreshold]);
        $result = $measure->again();
        unset($this->documents, $this->semanticFilterFigures);

        Flux::toast(variant: 'success', duration: 8000, text: __('Saved. :measured documents measured again, :below below the threshold.', $result));
    }

    // Queue the semantic filter for the fetched documents not measured yet.
    public function applySemanticFilter(): void
    {
        $documents = Document::query()->where('status', 'fetched')->whereNull('excluded_by')->whereNull('likeness')->get();
        $documents->each(fn (Document $document) => ApplySemanticFilter::queueFor($document));

        Flux::toast(variant: 'success', text: __(':count documents queued for the semantic filter.', ['count' => $documents->count()]));
    }

    /**
     * Counts of the semantic filter: measured, below, examples, definitions.
     *
     * @return array{measured: int, below: int, like: int, unlike: int, like_definitions: int, unlike_definitions: int}
     */
    #[Computed]
    public function semanticFilterFigures(): array
    {
        $examples = SemanticFilterExample::query()->selectRaw('side, count(*) as count')->groupBy('side')->pluck('count', 'side');

        return [
            'measured' => Document::query()->whereNotNull('likeness')->count(),
            'below' => Document::query()->leftOut()->count(),
            'like' => (int) ($examples['like'] ?? 0),
            'unlike' => (int) ($examples['unlike'] ?? 0),
            ...array_map(fn (string $side): int => count(array_filter(EditorialPolicy::semanticFilter()['definitions'], fn (array $definition): bool => $definition['side'] === $side)), ['like_definitions' => 'like', 'unlike_definitions' => 'unlike']),
        ];
    }

    // Queue the screening of the fetched documents not screened yet.
    public function screenDocuments(): void
    {
        $documents = Document::query()->where('status', 'fetched')->whereNull('excluded_by')->whereNull('latest_screening_id')
            ->notLeftOut()->get();
        $documents->each(fn (Document $document) => ScreenDocument::queueFor($document));
        unset($this->documents);

        Flux::toast(variant: 'success', text: __(':count documents queued for screening.', ['count' => $documents->count()]));
    }

    // Screen again the rejects decided by an older prompt version.
    public function rescreenRejected(): void
    {
        $prompt = Prompt::forLayer('content_filtering');
        $documents = Document::query()->whereHas('latestScreening', fn ($screening) => $screening->where('decision', 'reject')->where('prompt_id', '!=', $prompt->id))
            ->notLeftOut()->get();
        $documents->each(fn (Document $document) => ScreenDocument::queueFor($document));
        unset($this->documents);

        Flux::toast(variant: 'success', text: __(':count documents queued for screening.', ['count' => $documents->count()]));
    }

    /**
     * The figures of the screenings, per prompt version and per reason class.
     *
     * @return array{versions: list<array<string, mixed>>, reasons: list<array{reason_class: string, decision: string, meaning: string, count: int, share: float}>}
     */
    #[Computed]
    public function screeningFigures(): array
    {
        return app(ScreeningFigures::class)();
    }

    /** The sortable columns and the SQL each orders by. */
    public const SORTS = ['source' => 'sources.name', 'published_at' => 'published_at', 'format' => 'format', 'fetched_at' => 'fetched_at'];

    #[Url]
    public string $sort = 'fetched_at';

    #[Url]
    public string $direction = 'desc';

    // Filters, kept in the URL; empty means any.
    #[Url]
    public string $source = '';

    #[Url]
    public string $publishedFrom = '';

    #[Url]
    public string $publishedTo = '';

    #[Url]
    public string $format = '';

    #[Url]
    public string $fetchedFrom = '';

    #[Url]
    public string $fetchedTo = '';

    /** UI: 判定 — adopt / reject / review, or none */
    #[Url]
    public string $decision = '';

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Document> the filtered, sorted page */
    #[Computed]
    public function documents()
    {
        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : 'fetched_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        // Every document, whatever its state; id breaks ties.
        return Document::query()->with('source', 'material', 'latestScreening')
            ->when($sort === 'source', fn ($query) => $query->join('sources', 'sources.id', '=', 'documents.source_id')->select('documents.*'))
            ->when($this->source !== '', fn ($query) => $query->where('documents.source_id', $this->source))
            ->when($this->format !== '', fn ($query) => $query->where('format', $this->format))
            ->when($this->publishedFrom !== '', fn ($query) => $query->whereDate('published_at', '>=', $this->publishedFrom))
            ->when($this->publishedTo !== '', fn ($query) => $query->whereDate('published_at', '<=', $this->publishedTo))
            // Fetched days are in the display timezone.
            ->when($this->fetchedFrom !== '', fn ($query) => $query->where('fetched_at', '>=', $this->displayDay($this->fetchedFrom)))
            ->when($this->fetchedTo !== '', fn ($query) => $query->where('fetched_at', '<', $this->displayDay($this->fetchedTo)->addDay()))
            ->when($this->decision === 'none', fn ($query) => $query->undecided())
            ->when(in_array($this->decision, Screening::DECISIONS, true), fn ($query) => $query->decidedAs($this->decision))
            // Nulls last in either direction.
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' NULLS LAST')
            ->when($sort === 'source', fn ($query) => $query->orderBy('documents.title', $direction))
            ->orderBy('documents.id', $direction)
            ->paginate($this->rowsPerPage());
    }

    /** @return \Illuminate\Support\Collection<int, Source> the sources to filter by */
    #[Computed]
    public function sources()
    {
        return Source::query()->orderBy('name')->get(['id', 'name']);
    }

    // The start of a day of the display timezone, in UTC.
    private function displayDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, (string) config('app.display_timezone'))->startOfDay()->utc();
    }

    // Sort by a column; again reverses the order.
    public function sortBy(string $column): void
    {
        $this->direction = $this->sort === $column && $this->direction === 'desc' ? 'asc' : 'desc';
        $this->sort = $column;
        $this->resetPage();
    }

    // Correct a document's language (UI: 言語).
    public function setLanguage(int $documentId, string $language): void
    {
        abort_unless(in_array($language, \App\Enums\Language::codes(), true), 422);
        Document::query()->whereKey($documentId)->firstOrFail()->update(['language' => $language]);
        unset($this->documents);
    }

    // Back to the first page when a filter changes.
    public function updated(string $property): void
    {
        if (in_array($property, ['source', 'publishedFrom', 'publishedTo', 'format', 'fetchedFrom', 'fetchedTo', 'decision'], true)) {
            $this->resetPage();
        }
    }

}; ?>

<section class="w-full space-y-6" @if ($this->documents->contains('status', 'fetching') || $this->documents->contains(fn ($document) => $document->latestScreening?->status === 'screening')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Documents') }}</flux:heading>

    {{-- 意味フィルタ --}}
    <form wire:submit="saveSemanticFilter" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Semantic filter') }}</flux:heading>
        <flux:text>{{ __('Before the screening, for every source: a document\'s title and text are embedded and compared with the definitions and the examples of each side. Likeness is how much nearer the nearest "like" is than the nearest "unlike"; below the threshold a document goes no further. Much cheaper than the screening, and coarse: it cuts what is clearly unlike this media, and the screening judges the rest.') }}</flux:text>
        {{-- Links to the らしい / らしくない screens. --}}
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach (\App\Models\SemanticFilterExample::SIDES as $side => $label)
                <a href="{{ route('editorial.semantic-filter.show', $side) }}" class="rounded-lg border border-neutral-200 p-3 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800" wire:navigate>
                    <div class="font-medium">{{ __($label) }} →</div>
                    <div class="text-sm text-neutral-500">{{ __(':definitions definitions, :examples examples', ['definitions' => $this->semanticFilterFigures[$side.'_definitions'], 'examples' => $this->semanticFilterFigures[$side]]) }}</div>
                </a>
            @endforeach
        </div>
        <div class="grid gap-3 md:grid-cols-[1fr_12rem]">
            <x-pages::model-select wire:model="semanticFilterModel" :label="__('Embedding model')" :models="\App\Models\EditorialPolicy::EMBEDDING_MODELS" detail="described" />
            <flux:input wire:model="semanticFilterThreshold" :label="__('Threshold')" type="number" step="0.01" min="-1" max="1" />
        </div>
        <flux:text size="sm" class="text-neutral-500">{{ __(':measured documents measured, :below below the threshold.', $this->semanticFilterFigures) }}</flux:text>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="applySemanticFilter" icon="funnel">{{ __('Apply the semantic filter to the documents not measured yet') }}</flux:button>
        </div>
    </form>

    {{-- コンテンツフィルタリング --}}
    <form wire:submit="saveContentFiltering" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Content filtering') }}</flux:heading>
        <flux:text>{{ __('The developer prompt and the model of the screening: an LLM reads a fetched document and decides whether it goes on to the material (adopt), stops here (reject) or needs a look (review). The prompt is the same for every document and is served from the cache; a changed prompt is a new version, and the figures below are kept per version. The title filter, applied before fetching, is on the Sources screen.') }}</flux:text>
        <flux:textarea wire:model="contentFiltering" :label="__('Developer prompt (editable)')" rows="8" />
        <x-pages::fixed-prompts :instruction="\App\Actions\ProposeDecision::SECOND_PASS.' '.__('(second pass only)')" :input="[__('The document, as Markdown')]" />
        <x-pages::model-select wire:model="contentFilteringModel" :label="__('First-pass model (a document the first pass sends to review is judged again by the next model up)')" class="max-w-xl" :enabled="\App\Models\EditorialPolicy::screeningModels()" />
        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="screenDocuments" icon="scale" wire:confirm="{{ __('Screen every fetched document not screened yet? Each one is one call to the model.') }}">{{ __('Screen the documents not screened yet') }}</flux:button>
            <flux:button type="button" wire:click="rescreenRejected" icon="arrow-path" wire:confirm="{{ __('Screen again every document an older version of the prompt rejected? Each one is one call to the model.') }}">{{ __('Judge the rejected documents again') }}</flux:button>
        </div>

        {{-- Screening figures per prompt version. --}}
        @if ($this->screeningFigures['versions'] !== [])
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-neutral-500">
                        <tr>
                            @foreach ([__('Prompt version'), __('Screenings'), __('adopt'), __('reject'), __('review'), __('Cache hit rate'), __('Cache write rate'), __('Average cost'), __('Cost per adoption')] as $column)
                                <th class="whitespace-nowrap px-3 py-1 font-medium">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($this->screeningFigures['versions'] as $row)
                            <tr>
                                <td class="px-3 py-1">v{{ $row['version'] }}</td>
                                <td class="px-3 py-1">{{ $row['screened'] }}</td>
                                @foreach (['adopted', 'rejected', 'reviewed'] as $decision)
                                    <td class="px-3 py-1">{{ $row[$decision] }}（{{ number_format(100 * $row[$decision] / max(1, $row['screened']), 0) }}%）</td>
                                @endforeach
                                <td class="px-3 py-1">{{ $row['cache_hit_rate'] !== null ? number_format(100 * $row['cache_hit_rate'], 1).'%' : '—' }}</td>
                                <td class="px-3 py-1">{{ $row['cache_write_rate'] !== null ? number_format(100 * $row['cache_write_rate'], 1).'%' : '—' }}</td>
                                <td class="px-3 py-1">{{ $row['average_cost'] !== null ? '$'.number_format($row['average_cost'], 4) : '—' }}</td>
                                <td class="px-3 py-1">{{ $row['cost_per_adopt'] !== null ? '$'.number_format($row['cost_per_adopt'], 4) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{-- Reason classes of the latest screenings. --}}
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-neutral-500">
                        <tr>
                            @foreach ([__('Decision'), __('Reason class'), __('Meaning'), __('Documents'), __('Share')] as $column)
                                <th class="whitespace-nowrap px-3 py-1 font-medium">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($this->screeningFigures['reasons'] as $reason)
                            <tr class="{{ $reason['count'] === 0 ? 'text-neutral-400' : '' }}">
                                <td class="whitespace-nowrap px-3 py-1">{{ __($reason['decision']) }}</td>
                                <td class="whitespace-nowrap px-3 py-1 font-mono text-xs">{{ $reason['reason_class'] }}</td>
                                <td class="px-3 py-1">{{ __($reason['meaning']) }}</td>
                                <td class="px-3 py-1 text-right">{{ $reason['count'] }}</td>
                                <td class="px-3 py-1 text-right">{{ number_format(100 * $reason['share'], 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </form>

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="source" :label="__('Source')" size="sm" class="w-64!">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($this->sources as $option)
                <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="publishedFrom" :label="__('Published on')" type="date" size="sm" />
        <flux:input wire:model.live="publishedTo" label="〜" type="date" size="sm" />
        <flux:select wire:model.live="format" :label="__('Format')" size="sm" class="w-28!">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach (\App\Models\Document::FORMATS as $option)
                <flux:select.option value="{{ $option }}">{{ strtoupper($option) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="fetchedFrom" :label="__('Fetched at')" type="date" size="sm" />
        <flux:input wire:model.live="fetchedTo" label="〜" type="date" size="sm" />
        <flux:select wire:model.live="decision" :label="__('Decision')" size="sm" class="w-36!">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach (\App\Models\Screening::DECISIONS as $option)
                <flux:select.option value="{{ $option }}">{{ __($option) }}</flux:select.option>
            @endforeach
            <flux:select.option value="none">{{ __('Not screened') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Two rows per document: the title, then the details. --}}
    <x-pages::table
        :columns="[['label' => __('Source / Title'), 'sort' => 'source'], ['label' => __('Published on'), 'sort' => 'published_at'], __('Status'), __('Decision'), __('Language'), ['label' => __('Format'), 'sort' => 'format'], ['label' => __('Fetched at'), 'sort' => 'fetched_at'], __('Material')]"
        :sort="$sort" :direction="$direction" :empty="$this->documents->isEmpty()">
        @foreach ($this->documents as $document)
            <tr class="border-b-0" wire:key="title-{{ $document->id }}">
                <td colspan="8" class="px-3 pt-2 pb-0"><x-pages::favicon :source="$document->source" /> <a href="{{ route('editorial.documents.show', $document) }}" class="underline" wire:navigate>{{ $document->title }}</a></td>
            </tr>
            <tr wire:key="details-{{ $document->id }}">
                <td class="px-3 pt-1 pb-2 text-neutral-500"><a href="{{ route('editorial.sources.show', $document->source) }}" class="underline" wire:navigate>{{ $document->source->name }}</a></td>
                <td class="whitespace-nowrap px-3 pt-1 pb-2 text-neutral-500">{{ $document->published_at?->format('Y-m-d') }}</td>
                <td class="px-3 pt-1 pb-2">
                    @if ($document->excluded_by !== null)
                        <flux:tooltip :content="__('Excluded by keyword: :keyword', ['keyword' => $document->excluded_by])"><x-pages::status status="excluded" /></flux:tooltip>
                    @elseif ($document->isLeftOut())
                        <flux:tooltip :content="__('Left out by the semantic filter: likeness :likeness', ['likeness' => sprintf('%+.2f', $document->likeness)])"><x-pages::status status="left_out" /></flux:tooltip>
                    @elseif ($document->status === 'failed')
                        <flux:tooltip :content="$document->status_message ?? ''"><x-pages::status :status="$document->status" /></flux:tooltip>
                    @elseif ($document->hasShortBody())
                        <x-pages::status :status="$document->status" />
                        <flux:tooltip :content="__('Only :count characters: the document settings of the source may miss the body.', ['count' => mb_strlen((string) $document->markdown)])"><flux:badge size="sm" color="amber">{{ __('short body') }}</flux:badge></flux:tooltip>
                    @elseif ($document->status !== null)
                        <x-pages::status :status="$document->status" />
                    @else
                        —
                    @endif
                </td>
                <td class="px-3 pt-1 pb-2"><x-pages::decision :document="$document" />@if ($document->likeness !== null) <span class="whitespace-nowrap text-xs text-neutral-500">{{ __('Likeness') }} {{ sprintf('%+.2f', $document->likeness) }}</span>@endif</td>
                <td class="px-3 pt-1 pb-2">
                    <select wire:change="setLanguage({{ $document->id }}, $event.target.value)" aria-label="{{ __('Language') }}" class="rounded-md border border-neutral-200 bg-transparent px-1 py-0.5 text-sm dark:border-neutral-700">
                        @if ($document->language === null)
                            <option value="" selected>—</option>
                        @endif
                        @foreach (\App\Enums\Language::names() as $code => $name)
                            <option value="{{ $code }}" @selected($document->language === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </td>
                <td class="px-3 pt-1 pb-2 uppercase">{{ $document->format }}</td>
                <td class="whitespace-nowrap px-3 pt-1 pb-2 text-neutral-500">{{ $document->fetched_at?->display() ?? '—' }}</td>
                <td class="px-3 pt-1 pb-2">
                    @if ($document->material)
                        <a href="{{ route('editorial.materials.show', $document->material) }}" wire:navigate><x-pages::status :status="$document->material->status" /></a>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </x-pages::table>
    <x-pages::pagination :paginator="$this->documents" />
</section>
