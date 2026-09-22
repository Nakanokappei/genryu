<?php

use App\Jobs\ConfigureSource;
use App\Livewire\PagedList;
use App\Models\EditorialPolicy;
use App\Models\Source;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;

// 情報源 (Sources): list the sites we watch, add one by hand; and the selection layer of the editorial policy, one setting over every source, applied when their update lists are read.
new #[Title('情報源')] class extends PagedList {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    public string $notes = '';

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

    public function saveSelection(): void
    {
        foreach (['exclude_keywords' => $this->excludeKeywords, 'fetch_criteria' => $this->fetchCriteria, 'skip_criteria' => $this->skipCriteria] as $layer => $body) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $body]);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Source> */
    #[Computed]
    public function sources()
    {
        // The failed fetches are counted here so the list shows which source needs a look (its detail lists them with the reason).
        return Source::query()
            ->withCount(['documents', 'documents as failed_documents_count' => fn ($query) => $query->where('status', 'failed')])
            ->latest()->orderByDesc('id')->paginate($this->rowsPerPage());
    }

    // A new source is configured in the background (feed or agent-proposed HTML list settings).
    public function add(): void
    {
        $validated = $this->validate();
        $source = Source::create([...$validated, 'notes' => $this->notes !== '' ? $this->notes : null]);
        ConfigureSource::dispatch($source);
        $this->reset('name', 'url', 'notes');
        unset($this->sources);

        Flux::toast(variant: 'success', text: __('Configuration queued.'));
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Sources') }}</flux:heading>

    <form wire:submit="add" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-4 dark:border-neutral-700">
        <flux:input wire:model="name" :label="__('Name')" />
        <flux:input wire:model="url" :label="__('URL')" type="url" />
        <flux:input wire:model="notes" :label="__('Notes')" />
        <div class="flex items-end">
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Name'), __('Status'), __('Documents'), __('Failed fetches'), __('Created')]" :empty="$this->sources->isEmpty()">
        @foreach ($this->sources as $source)
            <tr>
                <td class="px-3 py-2">
                    <span class="inline-flex items-center gap-2">
                        <x-pages::favicon :source="$source" />
                        <a href="{{ route('sources.show', $source) }}" class="underline" wire:navigate>{{ $source->name }}</a>
                        {{-- The site itself: the address is the tooltip, not a column. --}}
                        <flux:tooltip :content="$source->url">
                            <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"><flux:icon.arrow-top-right-on-square variant="micro" /></a>
                        </flux:tooltip>
                    </span>
                </td>
                <td class="px-3 py-2"><x-pages::status :status="$source->status" /></td>
                <td class="px-3 py-2">{{ $source->documents_count }}</td>
                <td class="px-3 py-2 {{ $source->failed_documents_count > 0 ? 'text-red-600 dark:text-red-400' : 'text-neutral-500' }}">{{ $source->failed_documents_count }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $source->created_at->format('Y-m-d') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
    <x-pages::pagination :paginator="$this->sources" />

    {{-- The selection layer sits with the sources because it acts when their update lists are read: one setting for every source. --}}
    <form wire:submit="saveSelection" class="space-y-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Selection') }}</flux:heading>
        <flux:text>{{ __('One setting for every source, applied when its update list is read: what is listed but not fetched.') }}</flux:text>

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
</section>
