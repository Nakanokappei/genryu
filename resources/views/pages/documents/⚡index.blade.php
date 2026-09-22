<?php

use App\Livewire\PagedList;
use App\Models\EditorialPolicy;
use App\Models\Document;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// 文書 (Documents): the documents listed on the sources' update lists, each fetched (original kept, Markdown made) in the background, and the selection layer of the editorial policy that decides which of them are fetched.
new #[Title('文書')] class extends PagedList {
    // The selection layer: exclude keywords applied deterministically, criteria kept for the LLM judge.
    public string $excludeKeywords = '';

    public string $fetchCriteria = '';

    public string $skipCriteria = '';

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
        return Document::query()->with('source', 'material')->latest()->orderByDesc('id')->paginate($this->rowsPerPage());
    }

    public function saveSelection(): void
    {
        foreach (['exclude_keywords' => $this->excludeKeywords, 'fetch_criteria' => $this->fetchCriteria, 'skip_criteria' => $this->skipCriteria] as $layer => $body) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $body]);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->documents->contains('status', 'fetching')) wire:poll.5s @endif>
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

    <x-pages::table :columns="[__('Title'), __('Source'), __('Published at'), __('Status'), __('Format'), __('Fetched at'), __('Material')]" :empty="$this->documents->isEmpty()">
        @foreach ($this->documents as $document)
            <tr>
                <td class="px-3 py-2"><x-pages::favicon :source="$document->source" /> <a href="{{ route('documents.show', $document) }}" class="underline" wire:navigate>{{ $document->title }}</a></td>
                <td class="px-3 py-2"><a href="{{ route('sources.show', $document->source) }}" class="underline" wire:navigate>{{ $document->source->name }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $document->published_at?->format('Y-m-d') }}</td>
                <td class="px-3 py-2">
                    @if ($document->excluded_by !== null)
                        <flux:tooltip :content="__('Excluded by keyword: :keyword', ['keyword' => $document->excluded_by])"><x-pages::status status="excluded" /></flux:tooltip>
                    @elseif ($document->status !== null)
                        <x-pages::status :status="$document->status" />
                    @else
                        —
                    @endif
                </td>
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
