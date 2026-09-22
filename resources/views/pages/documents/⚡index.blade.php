<?php

use App\Livewire\PagedList;
use App\Models\EditorialPolicy;
use App\Models\Document;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

// 文書 (Documents): the documents fetched from the sources (original kept, Markdown made), sortable and filterable by source, published date, format and fetched time; and the selection layer of the editorial policy that decides which documents are fetched. A document whose fetch failed is listed on its source instead.
new #[Title('文書')] class extends PagedList {
    // The selection layer: exclude keywords applied deterministically, criteria kept for the LLM judge.
    public string $excludeKeywords = '';

    public string $fetchCriteria = '';

    public string $skipCriteria = '';

    /** The sortable columns (UI 情報源 / 公開日 / 形式 / 取得日時) and the SQL each one orders by. */
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

    public function mount(): void
    {
        $this->excludeKeywords = EditorialPolicy::bodyFor('exclude_keywords');
        $this->fetchCriteria = EditorialPolicy::bodyFor('fetch_criteria');
        $this->skipCriteria = EditorialPolicy::bodyFor('skip_criteria');
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Document> */
    #[Computed]
    public function documents()
    {
        $sort = array_key_exists($this->sort, self::SORTS) ? $this->sort : 'fetched_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        // Only the fetched documents are listed; the source's name is ordered through the sources table, and id breaks ties so pages never overlap.
        return Document::query()->with('source', 'material')->where('documents.status', 'fetched')
            ->when($sort === 'source', fn ($query) => $query->join('sources', 'sources.id', '=', 'documents.source_id')->select('documents.*'))
            ->when($this->source !== '', fn ($query) => $query->where('documents.source_id', $this->source))
            ->when($this->format !== '', fn ($query) => $query->where('format', $this->format))
            ->when($this->publishedFrom !== '', fn ($query) => $query->whereDate('published_at', '>=', $this->publishedFrom))
            ->when($this->publishedTo !== '', fn ($query) => $query->whereDate('published_at', '<=', $this->publishedTo))
            // The fetched time is stored in UTC and filtered by days of the display timezone.
            ->when($this->fetchedFrom !== '', fn ($query) => $query->where('fetched_at', '>=', $this->displayDay($this->fetchedFrom)))
            ->when($this->fetchedTo !== '', fn ($query) => $query->where('fetched_at', '<', $this->displayDay($this->fetchedTo)->addDay()))
            // A document without a date goes last either way rather than heading the list (PostgreSQL puts nulls first in descending order).
            ->orderByRaw(self::SORTS[$sort].' '.$direction.' NULLS LAST')->orderBy('documents.id', $direction)
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

    public function saveSelection(): void
    {
        foreach (['exclude_keywords' => $this->excludeKeywords, 'fetch_criteria' => $this->fetchCriteria, 'skip_criteria' => $this->skipCriteria] as $layer => $body) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $body]);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Documents') }}</flux:heading>

    <form wire:submit="saveSelection" class="space-y-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Selection') }}</flux:heading>

        <div class="space-y-2">
            <flux:subheading>{{ __('Deterministic screening') }}</flux:subheading>
            <flux:input wire:model="excludeKeywords" :label="__('Exclude keywords')" placeholder="採用情報; セミナー; イベント" />
            <flux:text size="sm">{{ __('Documents whose title contains one of these keywords are listed but not fetched. Separate several with a semicolon.') }}</flux:text>
        </div>

        <div class="space-y-2">
            <flux:subheading>{{ __('For the LLM') }}</flux:subheading>
            <div class="grid gap-3 md:grid-cols-2">
                <flux:textarea wire:model="fetchCriteria" :label="__('Criteria for fetching a document')" rows="5" />
                <flux:textarea wire:model="skipCriteria" :label="__('Criteria for not fetching a document')" rows="5" />
            </div>
            <flux:text size="sm">{{ __('Used as the system prompt of the LLM that judges new documents. Not applied yet.') }}</flux:text>
        </div>

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

    <x-pages::table
        :columns="[__('Title'), ['label' => __('Source'), 'sort' => 'source'], ['label' => __('Published at'), 'sort' => 'published_at'], ['label' => __('Format'), 'sort' => 'format'], ['label' => __('Fetched at'), 'sort' => 'fetched_at'], __('Material')]"
        :sort="$sort" :direction="$direction" :empty="$this->documents->isEmpty()">
        @foreach ($this->documents as $document)
            <tr>
                <td class="px-3 py-2"><x-pages::favicon :source="$document->source" /> <x-pages::short-title :title="$document->title" :href="route('documents.show', $document)" /></td>
                <td class="px-3 py-2"><a href="{{ route('sources.show', $document->source) }}" class="underline" wire:navigate>{{ $document->source->name }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $document->published_at?->format('Y-m-d') }}</td>
                <td class="px-3 py-2 uppercase">{{ $document->format }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $document->fetched_at?->display() ?? '—' }}</td>
                <td class="px-3 py-2">
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
