<?php

use App\Jobs\ScreenDocument;
use App\Livewire\PagedList;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Screening;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

// 文書 (Documents): the content filtering of the editorial policy (the developer prompt and the model of the スクリーニング, the LLM gate that reads the fetched documents and decides 採用 / 不採用 / 要確認; the title filter is on 情報源), with the figures of the screenings run so far, and the documents fetched from the sources (original kept, Markdown made), sortable and filterable by source, published date, format, fetched time and decision, each with its state (fetched / fetching / failed / excluded by the title filter).
new #[Title('文書')] class extends PagedList {
    // Content filtering: the developer prompt (OpenAI's name for the system prompt) of the screening gate.
    public string $contentFiltering = '';

    /** The model of the first pass (UI: 初回判定モデル), one of EditorialPolicy::screeningModels(). */
    public string $contentFilteringModel = EditorialPolicy::DEFAULT_MODEL;

    public function mount(): void
    {
        $this->contentFiltering = EditorialPolicy::bodyFor('content_filtering');
        $this->contentFilteringModel = EditorialPolicy::modelFor('content_filtering');
    }

    public function saveContentFiltering(): void
    {
        $this->validate(['contentFilteringModel' => ['required', 'in:'.implode(',', EditorialPolicy::screeningModels())]]);
        EditorialPolicy::query()->updateOrCreate(['layer' => 'content_filtering'], ['body' => $this->contentFiltering, 'model' => $this->contentFilteringModel]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // The gate: queue the screening of every fetched document that has none yet (an excluded one never gets there).
    public function screenDocuments(): void
    {
        $documents = Document::query()->where('status', 'fetched')->whereNull('excluded_by')->whereNull('screening_id')->get();
        $documents->each(fn (Document $document) => ScreenDocument::queueFor($document));
        unset($this->documents);

        Flux::toast(variant: 'success', text: __(':count documents queued for screening.', ['count' => $documents->count()]));
    }

    /**
     * The figures of the screenings, per prompt version: how many were
     * screened and how they were decided, how much of the input came
     * from the cache or was written to it, and what a screening and an
     * adoption cost on average; the reason classes counted apart.
     *
     * @return array{versions: list<array<string, mixed>>, reasons: list<array{primary_reason: string, decision: string, meaning: string, count: int, share: float}>}
     */
    #[Computed]
    public function screeningFigures(): array
    {
        $versions = Screening::query()->where('status', 'screened')
            ->join('screening_prompts', 'screening_prompts.id', '=', 'screenings.screening_prompt_id')
            ->groupBy('screening_prompts.version')->orderByDesc('screening_prompts.version')
            ->selectRaw('screening_prompts.version, count(*) as screened, sum(case when decision = ? then 1 else 0 end) as adopted, sum(case when decision = ? then 1 else 0 end) as rejected, sum(case when decision = ? then 1 else 0 end) as reviewed, sum(input_tokens) as input_tokens, sum(cached_tokens) as cached_tokens, sum(cache_write_tokens) as cache_write_tokens, sum(output_tokens) as output_tokens, sum(estimated_total_cost) as cost', ['adopt', 'reject', 'review'])
            ->get();

        // The reason classes counted over the latest screening of each document, in the order of the gate's list.
        $counted = Screening::query()->where('status', 'screened')->whereIn('id', Document::query()->whereNotNull('screening_id')->select('screening_id'))->groupBy('primary_reason')->selectRaw('primary_reason, count(*) as count')->pluck('count', 'primary_reason');
        $total = max(1, (int) $counted->sum());
        $reasons = [];

        foreach (Screening::REASONS as $reason => $about) {
            $reasons[] = ['primary_reason' => $reason, 'decision' => $about['decision'], 'meaning' => $about['meaning'], 'count' => (int) ($counted[$reason] ?? 0), 'share' => (int) ($counted[$reason] ?? 0) / $total];
        }

        return [
            'versions' => $versions->map(fn ($row): array => [
                'version' => (int) $row->version,
                'screened' => (int) $row->screened,
                'adopted' => (int) $row->adopted,
                'rejected' => (int) $row->rejected,
                'reviewed' => (int) $row->reviewed,
                'cache_hit_rate' => $row->input_tokens > 0 ? $row->cached_tokens / $row->input_tokens : null,
                'cache_write_rate' => $row->input_tokens > 0 ? $row->cache_write_tokens / $row->input_tokens : null,
                'average_cost' => $row->cost !== null ? $row->cost / $row->screened : null,
                'cost_per_adopt' => $row->cost !== null && $row->adopted > 0 ? $row->cost / $row->adopted : null,
            ])->all(),
            'reasons' => $reasons,
        ];
    }

    /** The sortable columns (UI 情報源／タイトル / 公開日 / 形式 / 取得日時) and the SQL each one orders by; the source's documents are ordered by title within it. */
    public const SORTS = ['source' => 'sources.name', 'published_at' => 'published_at', 'format' => 'format', 'fetched_at' => 'fetched_at'];

    #[Url]
    public string $sort = 'fetched_at';

    #[Url]
    public string $direction = 'desc';

    // The filters, kept in the URL like the page size; an empty value means "any".
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

    /** The decision that stands (UI 判定): a person's, else the latest screening's: adopt / reject / review, or none for the documents nobody has decided. */
    #[Url]
    public string $decision = '';

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Document> */
    #[Computed]
    public function documents()
    {
        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : 'fetched_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        // Every listed document, whatever its state; the source's name is ordered through the sources table, and id breaks ties so pages never overlap.
        return Document::query()->with('source', 'material', 'screening')
            ->when($sort === 'source', fn ($query) => $query->join('sources', 'sources.id', '=', 'documents.source_id')->select('documents.*'))
            ->when($this->source !== '', fn ($query) => $query->where('documents.source_id', $this->source))
            ->when($this->format !== '', fn ($query) => $query->where('format', $this->format))
            ->when($this->publishedFrom !== '', fn ($query) => $query->whereDate('published_at', '>=', $this->publishedFrom))
            ->when($this->publishedTo !== '', fn ($query) => $query->whereDate('published_at', '<=', $this->publishedTo))
            // The fetched time is stored in UTC and filtered by days of the display timezone.
            ->when($this->fetchedFrom !== '', fn ($query) => $query->where('fetched_at', '>=', $this->displayDay($this->fetchedFrom)))
            ->when($this->fetchedTo !== '', fn ($query) => $query->where('fetched_at', '<', $this->displayDay($this->fetchedTo)->addDay()))
            // The decision that stands: a person's, else the latest screening's.
            ->when($this->decision === 'none', fn ($query) => $query->whereNull('human_decision')->whereNull('screening_id'))
            ->when(in_array($this->decision, Screening::DECISIONS, true), fn ($query) => $query->where(fn ($query) => $query->where('human_decision', $this->decision)->orWhere(fn ($query) => $query->whereNull('human_decision')->whereRelation('screening', 'decision', $this->decision))))
            // A document without a date goes last either way rather than heading the list (PostgreSQL puts nulls first in descending order).
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

    // Clicking a heading sorts by it; clicking it again turns the order round.
    public function sortBy(string $column): void
    {
        $this->direction = $this->sort === $column && $this->direction === 'desc' ? 'asc' : 'desc';
        $this->sort = $column;
        $this->resetPage();
    }

    // A changed filter starts again from the first page.
    public function updated(string $property): void
    {
        if (in_array($property, ['source', 'publishedFrom', 'publishedTo', 'format', 'fetchedFrom', 'fetchedTo', 'decision'], true)) {
            $this->resetPage();
        }
    }

}; ?>

<section class="w-full space-y-6" @if ($this->documents->contains('status', 'fetching') || $this->documents->contains(fn ($document) => $document->screening?->status === 'screening')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Documents') }}</flux:heading>

    {{-- Content filtering sits with the documents because it judges what was fetched: the criteria an LLM reads a document by. --}}
    <form wire:submit="saveContentFiltering" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Content filtering') }}</flux:heading>
        <flux:text>{{ __('The developer prompt and the model of the screening: an LLM reads a fetched document and decides whether it goes on to the material (adopt), stops here (reject) or needs a look (review). The prompt is the same for every document and is served from the cache; a changed prompt is a new version, and the figures below are kept per version. The title filter, applied before fetching, is on the Sources screen.') }}</flux:text>
        <flux:textarea wire:model="contentFiltering" :label="__('Developer prompt')" rows="8" />
        <flux:select wire:model="contentFilteringModel" :label="__('First-pass model (a document the first pass sends to review is judged again by the next model up)')" class="max-w-xl">
            @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                <flux:select.option value="{{ $id }}" :disabled="! in_array($id, \App\Models\EditorialPolicy::screeningModels(), true)">{{ $model['name'] }}（{{ $id }}）— {{ __($model['description']) }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="screenDocuments" icon="scale" wire:confirm="{{ __('Screen every fetched document not screened yet? Each one is one call to the model.') }}">{{ __('Screen the documents not screened yet') }}</flux:button>
        </div>

        {{-- The figures per prompt version, and the reason classes counted. --}}
        @if ($this->screeningFigures['versions'] !== [])
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-neutral-500">
                        <tr>
                            @foreach ([__('Prompt version'), __('Screened'), __('adopt'), __('reject'), __('review'), __('Cache hit rate'), __('Cache write rate'), __('Average cost'), __('Cost per adoption')] as $column)
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
            {{-- The reason classes, as the latest screening of each document named them. --}}
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-neutral-500">
                        <tr>
                            @foreach ([__('Decision'), __('Reason'), __('Meaning'), __('Documents'), __('Share')] as $column)
                                <th class="whitespace-nowrap px-3 py-1 font-medium">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($this->screeningFigures['reasons'] as $reason)
                            <tr class="{{ $reason['count'] === 0 ? 'text-neutral-400' : '' }}">
                                <td class="whitespace-nowrap px-3 py-1">{{ __($reason['decision']) }}</td>
                                <td class="whitespace-nowrap px-3 py-1 font-mono text-xs">{{ $reason['primary_reason'] }}</td>
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

    {{-- The filters: one per sortable column, applied as soon as they change. --}}
    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="source" :label="__('Source')" size="sm" class="w-64!">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($this->sources as $option)
                <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="publishedFrom" :label="__('Published at')" type="date" size="sm" />
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

    {{-- Two rows per document: the title on its own line (whole, it has the width now), the rest beneath it, so the table is not cramped. --}}
    <x-pages::table
        :columns="[['label' => __('Source / Title'), 'sort' => 'source'], ['label' => __('Published at'), 'sort' => 'published_at'], __('Status'), __('Decision'), ['label' => __('Format'), 'sort' => 'format'], ['label' => __('Fetched at'), 'sort' => 'fetched_at'], __('Material')]"
        :sort="$sort" :direction="$direction" :empty="$this->documents->isEmpty()">
        @foreach ($this->documents as $document)
            <tr class="border-b-0" wire:key="title-{{ $document->id }}">
                <td colspan="7" class="px-3 pt-2 pb-0"><x-pages::favicon :source="$document->source" /> <a href="{{ route('documents.show', $document) }}" class="underline" wire:navigate>{{ $document->title }}</a></td>
            </tr>
            <tr wire:key="details-{{ $document->id }}">
                <td class="px-3 pt-1 pb-2 text-neutral-500"><a href="{{ route('sources.show', $document->source) }}" class="underline" wire:navigate>{{ $document->source->name }}</a></td>
                <td class="whitespace-nowrap px-3 pt-1 pb-2 text-neutral-500">{{ $document->published_at?->format('Y-m-d') }}</td>
                <td class="px-3 pt-1 pb-2">
                    @if ($document->excluded_by !== null)
                        <flux:tooltip :content="__('Excluded by keyword: :keyword', ['keyword' => $document->excluded_by])"><x-pages::status status="excluded" /></flux:tooltip>
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
                <td class="px-3 pt-1 pb-2"><x-pages::decision :document="$document" /></td>
                <td class="px-3 pt-1 pb-2 uppercase">{{ $document->format }}</td>
                <td class="whitespace-nowrap px-3 pt-1 pb-2 text-neutral-500">{{ $document->fetched_at?->display() ?? '—' }}</td>
                <td class="px-3 pt-1 pb-2">
                    @if ($document->material)
                        <a href="{{ route('materials.show', $document->material) }}" wire:navigate><x-pages::status :status="$document->material->status" /></a>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </x-pages::table>
    <x-pages::pagination :paginator="$this->documents" />
</section>
