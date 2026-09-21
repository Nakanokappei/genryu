<?php

use App\Models\Source;
use App\Models\UpdateEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 更新リスト (Updates): items found on the sources' update lists, added by hand for now.
new #[Title('更新リスト')] class extends Component {
    #[Validate('required|exists:sources,id')]
    public string $source_id = '';

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    #[Validate('nullable|date')]
    public string $published_at = '';

    /** @return \Illuminate\Database\Eloquent\Collection<int, UpdateEntry> */
    #[Computed]
    public function updates()
    {
        return UpdateEntry::query()->with('source', 'document')->latest()->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Source> */
    #[Computed]
    public function sources()
    {
        return Source::query()->orderBy('name')->get();
    }

    public function add(): void
    {
        $validated = $this->validate();
        UpdateEntry::create([...$validated, 'published_at' => $this->published_at !== '' ? $this->published_at : null]);
        $this->reset('title', 'url', 'published_at');
        unset($this->updates);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Updates') }}</flux:heading>

    <form wire:submit="add" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-5 dark:border-neutral-700">
        <flux:select wire:model="source_id" :label="__('Source')" :placeholder="__('Select')">
            @foreach ($this->sources as $source)
                <flux:select.option value="{{ $source->id }}">{{ $source->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="title" :label="__('Title')" />
        <flux:input wire:model="url" :label="__('URL')" type="url" />
        <flux:input wire:model="published_at" :label="__('Published at')" type="date" />
        <div class="flex items-end">
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Title'), __('Source'), __('Published at'), __('Document')]" :empty="$this->updates->isEmpty()">
        @foreach ($this->updates as $update)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('updates.show', $update) }}" class="underline" wire:navigate>{{ $update->title }}</a></td>
                <td class="px-3 py-2"><a href="{{ route('sources.show', $update->source) }}" class="underline" wire:navigate>{{ $update->source->name }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $update->published_at?->format('Y-m-d') }}</td>
                <td class="px-3 py-2">
                    @if ($update->document)
                        <a href="{{ route('documents.show', $update->document) }}" wire:navigate><x-pages::status :status="$update->document->status" /></a>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
