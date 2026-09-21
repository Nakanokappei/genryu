<?php

use App\Models\Source;
use Livewire\Attributes\Title;
use Livewire\Component;

// 情報源 (Source) detail: the site and the updates found on it.
new #[Title('情報源')] class extends Component {
    public Source $source;
}; ?>

<section class="w-full space-y-6">
    <x-pages::detail-header :back="route('sources.index')" :back-label="__('Sources')" :title="$source->name" />

    <x-pages::fields :fields="[
        __('URL') => $source->url,
        __('Notes') => $source->notes,
        __('Created') => $source->created_at->format('Y-m-d H:i'),
    ]" />

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
