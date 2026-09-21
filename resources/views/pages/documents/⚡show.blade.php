<?php

use App\Models\Document;
use Livewire\Attributes\Title;
use Livewire\Component;

// 文書 (Document) detail: where it came from, the Markdown, and the materials extracted from it.
new #[Title('文書')] class extends Component {
    public Document $document;
}; ?>

<section class="w-full space-y-6">
    <x-pages::detail-header :back="route('documents.index')" :back-label="__('Documents')" :title="$document->title" />

    <x-pages::fields :fields="[
        __('Source') => $document->updateEntry->source->name,
        __('Update') => $document->updateEntry->title,
        __('URL') => $document->url,
        __('Format') => strtoupper($document->format),
        __('Original') => $document->original_path,
        __('Fetched at') => $document->fetched_at?->format('Y-m-d H:i') ?? __('Not fetched yet.'),
    ]" />

    <flux:heading size="lg">{{ __('Markdown') }}</flux:heading>
    <pre class="max-h-96 overflow-auto rounded-xl border border-neutral-200 p-4 text-sm whitespace-pre-wrap dark:border-neutral-700">{{ $document->markdown ?? __('Not fetched yet.') }}</pre>

    <flux:heading size="lg">{{ __('Materials') }}</flux:heading>
    <x-pages::table :columns="[__('Data'), __('Created')]" :empty="$document->materials->isEmpty()">
        @foreach ($document->materials as $material)
            <tr>
                <td class="max-w-xl truncate px-3 py-2"><a href="{{ route('materials.show', $material) }}" class="underline" wire:navigate>{{ json_encode($material->data, JSON_UNESCAPED_UNICODE) }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $material->created_at->format('Y-m-d H:i') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
