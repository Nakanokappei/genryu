<?php

use App\Models\Source;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 情報源 (Source) detail: edit or delete the site, and see the updates found on it.
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
            <flux:text class="ms-auto">{{ __('Created') }}: {{ $source->created_at->format('Y-m-d H:i') }}</flux:text>
        </div>
    </form>

    <flux:heading size="lg">{{ __('Updates') }}</flux:heading>
    <x-pages::table :columns="[__('Title'), __('Published at')]" :empty="$source->updateEntries->isEmpty()">
        @foreach ($source->updateEntries()->latest()->get() as $update)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('updates.show', $update) }}" class="underline" wire:navigate>{{ $update->title }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $update->published_at?->format('Y-m-d') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
