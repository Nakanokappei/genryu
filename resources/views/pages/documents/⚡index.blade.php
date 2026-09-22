<?php

use App\Livewire\PagedList;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

// 文書 (Documents): the content filtering of the editorial policy (the developer prompt of an LLM judge that reads the fetched documents; the title filter is on 情報源), and the documents fetched from the sources (original kept, Markdown made), sortable and filterable by source, published date, format and fetched time, each with its state (fetched / fetching / failed / excluded by the title filter).
new #[Title('文書')] class extends PagedList {
    // Content filtering: the developer prompt (OpenAI's name for the system prompt) kept for the LLM judge, which is not built yet.
    public string $contentFiltering = '';

    /** The model the judge runs on (UI: モデル), one of EditorialPolicy::MODELS. */
    public string $contentFilteringModel = EditorialPolicy::DEFAULT_MODEL;

    public function mount(): void
    {
        $this->contentFiltering = EditorialPolicy::bodyFor('content_filtering');
        $this->contentFilteringModel = EditorialPolicy::modelFor('content_filtering');
    }

    public function saveContentFiltering(): void
    {
        $this->validate(['contentFilteringModel' => ['required', 'in:'.implode(',', array_keys(EditorialPolicy::MODELS))]]);
        EditorialPolicy::query()->updateOrCreate(['layer' => 'content_filtering'], ['body' => $this->contentFiltering, 'model' => $this->contentFilteringModel]);

        Flux::toast(variant: 'success', text: __('Saved.'));
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

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Document> */
    #[Computed]
    public function documents()
    {
        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : 'fetched_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        // Every listed document, whatever its state; the source's name is ordered through the sources table, and id breaks ties so pages never overlap.
        return Document::query()->with('source', 'material')
            ->when($sort === 'source', fn ($query) => $query->join('sources', 'sources.id', '=', 'documents.source_id')->select('documents.*'))
            ->when($this->source !== '', fn ($query) => $query->where('documents.source_id', $this->source))
            ->when($this->format !== '', fn ($query) => $query->where('format', $this->format))
            ->when($this->publishedFrom !== '', fn ($query) => $query->whereDate('published_at', '>=', $this->publishedFrom))
            ->when($this->publishedTo !== '', fn ($query) => $query->whereDate('published_at', '<=', $this->publishedTo))
            // The fetched time is stored in UTC and filtered by days of the display timezone.
            ->when($this->fetchedFrom !== '', fn ($query) => $query->where('fetched_at', '>=', $this->displayDay($this->fetchedFrom)))
            ->when($this->fetchedTo !== '', fn ($query) => $query->where('fetched_at', '<', $this->displayDay($this->fetchedTo)->addDay()))
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
        if (in_array($property, ['source', 'publishedFrom', 'publishedTo', 'format', 'fetchedFrom', 'fetchedTo'], true)) {
            $this->resetPage();
        }
    }

}; ?>

<section class="w-full space-y-6" @if ($this->documents->contains('status', 'fetching')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Documents') }}</flux:heading>

    {{-- Content filtering sits with the documents because it judges what was fetched: the criteria an LLM reads a document by. --}}
    <form wire:submit="saveContentFiltering" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Content filtering') }}</flux:heading>
        <flux:text>{{ __('Used as the developer prompt of the LLM that reads a fetched document and judges whether it goes on. Not applied yet. The title filter, applied before fetching, is on the Sources screen.') }}</flux:text>
        <flux:textarea wire:model="contentFiltering" :label="__('Developer prompt')" rows="8" />
        <flux:select wire:model="contentFilteringModel" :label="__('Model')" class="max-w-xl">
            @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                <flux:select.option value="{{ $id }}">{{ $model['name'] }}（{{ $id }}）— {{ __($model['description']) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
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
    </div>

    {{-- Two rows per document: the title on its own line (whole, it has the width now), the rest beneath it, so the table is not cramped. --}}
    <x-pages::table
        :columns="[['label' => __('Source / Title'), 'sort' => 'source'], ['label' => __('Published at'), 'sort' => 'published_at'], __('Status'), ['label' => __('Format'), 'sort' => 'format'], ['label' => __('Fetched at'), 'sort' => 'fetched_at'], __('Material')]"
        :sort="$sort" :direction="$direction" :empty="$this->documents->isEmpty()">
        @foreach ($this->documents as $document)
            <tr class="border-b-0" wire:key="title-{{ $document->id }}">
                <td colspan="6" class="px-3 pt-2 pb-0"><x-pages::favicon :source="$document->source" /> <a href="{{ route('documents.show', $document) }}" class="underline" wire:navigate>{{ $document->title }}</a></td>
            </tr>
            <tr wire:key="details-{{ $document->id }}">
                <td class="px-3 pt-1 pb-2 text-neutral-500"><a href="{{ route('sources.show', $document->source) }}" class="underline" wire:navigate>{{ $document->source->name }}</a></td>
                <td class="whitespace-nowrap px-3 pt-1 pb-2 text-neutral-500">{{ $document->published_at?->format('Y-m-d') }}</td>
                <td class="px-3 pt-1 pb-2">
                    @if ($document->excluded_by !== null)
                        <flux:tooltip :content="__('Excluded by keyword: :keyword', ['keyword' => $document->excluded_by])"><x-pages::status status="excluded" /></flux:tooltip>
                    @elseif ($document->status === 'failed')
                        <flux:tooltip :content="$document->status_message ?? ''"><x-pages::status :status="$document->status" /></flux:tooltip>
                    @elseif ($document->status !== null)
                        <x-pages::status :status="$document->status" />
                    @else
                        —
                    @endif
                </td>
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
