<?php

use App\Actions\FetchUpdates;
use App\Models\Source;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 情報源 (Source) detail: edit or delete the site, fetch its update list, and see what was found.
new #[Title('情報源')] class extends Component {
    public Source $source;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    public string $notes = '';

    public function mount(): void
    {
        $this->name = $this->source->name;
        $this->url = $this->source->url;
        $this->notes = $this->source->notes ?? '';
    }

    public function save(): void
    {
        $validated = $this->validate();
        $this->source->update([...$validated, 'notes' => $this->notes !== '' ? $this->notes : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Deleting a source takes its updates, documents and materials with it (cascade).
    public function delete(): void
    {
        $this->source->delete();

        $this->redirectRoute('sources.index', navigate: true);
    }

    // Stage 2.1: read the feed (found deterministically) into the update list.
    public function fetchUpdates(FetchUpdates $fetch): void
    {
        try {
            $result = $fetch($this->source);
        } catch (\Throwable $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage(), duration: 8000);

            return;
        }

        $this->source->refresh();
        Flux::toast(variant: 'success', text: __(':added added, :existing already listed (:feed)', ['added' => $result['added'], 'existing' => $result['existing'], 'feed' => $result['feed_url']]), duration: 8000);
    }
}; ?>

<section class="w-full space-y-6">
    <x-pages::detail-header :back="route('sources.index')" :back-label="__('Sources')" :title="$source->name" />

    <form wire:submit="save" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-3 dark:border-neutral-700">
        <flux:input wire:model="name" :label="__('Name')" />
        <flux:input wire:model="url" :label="__('URL')" type="url" />
        <flux:input wire:model="notes" :label="__('Notes')" />
        <div class="flex items-center gap-3 md:col-span-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this source and everything found under it?') }}">{{ __('Delete') }}</flux:button>
            <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="text-sm underline">{{ __('Open') }} ↗</a>
            <flux:text class="ms-auto">{{ __('Created') }}: {{ $source->created_at->format('Y-m-d H:i') }}</flux:text>
        </div>
    </form>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:button wire:click="fetchUpdates" variant="primary" icon="arrow-path">{{ __('Fetch updates') }}</flux:button>
        <flux:text>
            {{ __('Feed') }}:
            @if ($source->feed_url)
                <a href="{{ $source->feed_url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $source->feed_url }}</a>
            @else
                —
            @endif
        </flux:text>
        <flux:text class="ms-auto">{{ __('Fetched at') }}: {{ $source->fetched_at?->format('Y-m-d H:i') ?? '—' }}</flux:text>
    </div>

    <flux:heading size="lg">{{ __('Updates') }}</flux:heading>
    <x-pages::table :columns="[__('Title'), __('Published at')]" :empty="$source->updateEntries->isEmpty()">
        @foreach ($source->updateEntries()->latest('published_at')->latest('id')->get() as $update)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('updates.show', $update) }}" class="underline" wire:navigate>{{ $update->title }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $update->published_at?->format('Y-m-d') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
