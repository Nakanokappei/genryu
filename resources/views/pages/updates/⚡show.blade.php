<?php

use App\Models\UpdateEntry;
use Livewire\Attributes\Title;
use Livewire\Component;

// 更新情報 (Update) detail: the list item and the documents fetched for it.
new #[Title('更新情報')] class extends Component {
    public UpdateEntry $updateEntry;
}; ?>

<section class="w-full space-y-6">
    <x-pages::detail-header :back="route('updates.index')" :back-label="__('Updates')" :title="$updateEntry->title" />

    <x-pages::fields :fields="[
        __('Source') => $updateEntry->source->name,
        __('URL') => $updateEntry->url,
        __('Published at') => $updateEntry->published_at?->format('Y-m-d'),
        __('Created') => $updateEntry->created_at->format('Y-m-d H:i'),
    ]" />

    <flux:heading size="lg">{{ __('Documents') }}</flux:heading>
    <x-pages::table :columns="[__('Title'), __('Format'), __('Fetched at')]" :empty="$updateEntry->documents->isEmpty()">
        @foreach ($updateEntry->documents as $document)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('documents.show', $document) }}" class="underline" wire:navigate>{{ $document->title }}</a></td>
                <td class="px-3 py-2 uppercase">{{ $document->format }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $document->fetched_at?->format('Y-m-d H:i') ?? __('Not fetched yet.') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
