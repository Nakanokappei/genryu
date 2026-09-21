<?php

use App\Models\Document;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 文書 (Documents): the HTML / PDF fetched in the background for each update; nothing is added by hand here.
new #[Title('文書')] class extends Component {
    /** @return \Illuminate\Database\Eloquent\Collection<int, Document> */
    #[Computed]
    public function documents()
    {
        return Document::query()->with('updateEntry.source', 'material')->latest()->get();
    }
}; ?>

<section class="w-full space-y-6" @if ($this->documents->contains('status', 'fetching')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Documents') }}</flux:heading>

    <x-pages::table :columns="[__('Title'), __('Source'), __('Status'), __('Format'), __('Fetched at'), __('Material')]" :empty="$this->documents->isEmpty()">
        @foreach ($this->documents as $document)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('documents.show', $document) }}" class="underline" wire:navigate>{{ $document->title }}</a></td>
                <td class="px-3 py-2">{{ $document->updateEntry->source->name }}</td>
                <td class="px-3 py-2"><x-pages::status :status="$document->status" /></td>
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
</section>
