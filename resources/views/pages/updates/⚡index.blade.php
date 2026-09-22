<?php

use App\Livewire\PagedList;
use App\Models\EditorialPolicy;
use App\Models\UpdateEntry;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// 更新リスト (Updates): items found on the sources' update lists with the document fetched for each, and the selection layer of the editorial policy that decides which of them get their document fetched.
new #[Title('更新リスト')] class extends PagedList {
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

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, UpdateEntry> */
    #[Computed]
    public function updates()
    {
        return UpdateEntry::query()->with('source', 'material')->latest()->orderByDesc('id')->paginate($this->rowsPerPage());
    }

    public function saveSelection(): void
    {
        foreach (['exclude_keywords' => $this->excludeKeywords, 'fetch_criteria' => $this->fetchCriteria, 'skip_criteria' => $this->skipCriteria] as $layer => $body) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $body]);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->updates->contains('status', 'fetching')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Updates') }}</flux:heading>

    <form wire:submit="saveSelection" class="space-y-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Selection') }}</flux:heading>

        <div class="space-y-2">
            <flux:subheading>{{ __('Deterministic screening') }}</flux:subheading>
            <flux:input wire:model="excludeKeywords" :label="__('Exclude keywords')" placeholder="採用情報; セミナー; イベント" />
            <flux:text size="sm">{{ __('Updates whose title contains one of these keywords are listed but their document is not fetched. Separate several with a semicolon.') }}</flux:text>
        </div>

        <div class="space-y-2">
            <flux:subheading>{{ __('For the LLM') }}</flux:subheading>
            <div class="grid gap-3 md:grid-cols-2">
                <flux:textarea wire:model="fetchCriteria" :label="__('Criteria for fetching a document')" rows="5" />
                <flux:textarea wire:model="skipCriteria" :label="__('Criteria for not fetching a document')" rows="5" />
            </div>
            <flux:text size="sm">{{ __('Used as the system prompt of the LLM that judges new updates. Not applied yet.') }}</flux:text>
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>

    <x-pages::table :columns="[__('Title'), __('Source'), __('Published at'), __('Status'), __('Format'), __('Fetched at'), __('Material')]" :empty="$this->updates->isEmpty()">
        @foreach ($this->updates as $update)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('updates.show', $update) }}" class="underline" wire:navigate>{{ $update->title }}</a></td>
                <td class="px-3 py-2"><x-pages::source-name :source="$update->source" /></td>
                <td class="px-3 py-2 text-neutral-500">{{ $update->published_at?->format('Y-m-d') }}</td>
                <td class="px-3 py-2">
                    @if ($update->excluded_by !== null)
                        <flux:tooltip :content="__('Excluded by keyword: :keyword', ['keyword' => $update->excluded_by])"><x-pages::status status="excluded" /></flux:tooltip>
                    @elseif ($update->status !== null)
                        <x-pages::status :status="$update->status" />
                    @else
                        —
                    @endif
                </td>
                <td class="px-3 py-2 uppercase">{{ $update->format }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $update->fetched_at?->display() ?? '—' }}</td>
                <td class="px-3 py-2">
                    @if ($update->material)
                        <a href="{{ route('materials.show', $update->material) }}" wire:navigate><x-pages::status :status="$update->material->status" /></a>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </x-pages::table>
    <x-pages::pagination :paginator="$this->updates" />
</section>
