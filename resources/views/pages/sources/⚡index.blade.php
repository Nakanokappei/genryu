<?php

use App\Jobs\ConfigureSource;
use App\Livewire\PagedList;
use App\Models\Source;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;

// 情報源 (Sources): list the sites we watch, add one by hand.
new #[Title('情報源')] class extends PagedList {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    public string $notes = '';

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
</section>
