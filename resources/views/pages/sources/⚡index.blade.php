<?php

use App\Models\Source;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 情報源 (Sources): list the sites we watch, add one by hand.
new #[Title('情報源')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    public string $notes = '';

    /** @return \Illuminate\Database\Eloquent\Collection<int, Source> */
    #[Computed]
    public function sources()
    {
        return Source::query()->withCount('updateEntries')->latest()->get();
    }

    public function add(): void
    {
        $validated = $this->validate();
        Source::create([...$validated, 'notes' => $this->notes !== '' ? $this->notes : null]);
        $this->reset('name', 'url', 'notes');
        unset($this->sources);
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

    <x-pages::table :columns="[__('Name'), __('URL'), __('Updates'), __('Created')]" :empty="$this->sources->isEmpty()">
        @foreach ($this->sources as $source)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('sources.show', $source) }}" class="underline" wire:navigate>{{ $source->name }}</a></td>
                <td class="max-w-md truncate px-3 py-2 text-neutral-500">{{ $source->url }}</td>
                <td class="px-3 py-2">{{ $source->update_entries_count }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $source->created_at->format('Y-m-d') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
