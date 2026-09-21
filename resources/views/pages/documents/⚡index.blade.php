<?php

use App\Models\Document;
use App\Models\UpdateEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 文書 (Documents): the fetched HTML / PDF behind each update; pasted by hand for now.
new #[Title('文書')] class extends Component {
    #[Validate('required|exists:update_entries,id')]
    public string $update_entry_id = '';

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    #[Validate('required|in:html,pdf')]
    public string $format = 'html';

    public string $markdown = '';

    /** @return \Illuminate\Database\Eloquent\Collection<int, Document> */
    #[Computed]
    public function documents()
    {
        return Document::query()->with('updateEntry.source')->withCount('materials')->latest()->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, UpdateEntry> */
    #[Computed]
    public function updates()
    {
        return UpdateEntry::query()->with('source')->latest()->get();
    }

    public function add(): void
    {
        $validated = $this->validate();
        Document::create([
            ...$validated,
            'markdown' => $this->markdown !== '' ? $this->markdown : null,
            'fetched_at' => $this->markdown !== '' ? now() : null,
        ]);
        $this->reset('title', 'url', 'markdown');
        unset($this->documents);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Documents') }}</flux:heading>

    <form wire:submit="add" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-4 dark:border-neutral-700">
        <flux:select wire:model="update_entry_id" :label="__('Update')" :placeholder="__('Select')">
            @foreach ($this->updates as $update)
                <flux:select.option value="{{ $update->id }}">{{ $update->source->name }} / {{ $update->title }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="title" :label="__('Title')" />
        <flux:input wire:model="url" :label="__('URL')" type="url" />
        <flux:select wire:model="format" :label="__('Format')">
            <flux:select.option value="html">HTML</flux:select.option>
            <flux:select.option value="pdf">PDF</flux:select.option>
        </flux:select>
        <div class="md:col-span-4">
            <flux:textarea wire:model="markdown" :label="__('Markdown')" rows="6" />
        </div>
        <div class="flex items-end">
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Title'), __('Source'), __('Format'), __('Fetched at'), __('Materials')]" :empty="$this->documents->isEmpty()">
        @foreach ($this->documents as $document)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('documents.show', $document) }}" class="underline" wire:navigate>{{ $document->title }}</a></td>
                <td class="px-3 py-2">{{ $document->updateEntry->source->name }}</td>
                <td class="px-3 py-2 uppercase">{{ $document->format }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $document->fetched_at?->format('Y-m-d H:i') ?? __('Not fetched yet.') }}</td>
                <td class="px-3 py-2">{{ $document->materials_count }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
