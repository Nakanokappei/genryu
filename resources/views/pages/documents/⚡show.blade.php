<?php

use App\Jobs\FetchDocument;
use App\Models\Document;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 文書 (Document) detail: where it came from, how the fetch went, the original, the Markdown, and the materials extracted from it.
new #[Title('文書')] class extends Component {
    public Document $document;

    // Queue the fetch again (after a failure, or after the source's document settings changed).
    public function fetch(): void
    {
        FetchDocument::queueFor($this->document->updateEntry);
        $this->document->refresh();

        Flux::toast(variant: 'success', text: __('Document queued.'));
    }

    // Polled while fetching so the screen follows the background job.
    public function refreshStatus(): void
    {
        $this->document->refresh();
    }
}; ?>

<section class="w-full space-y-6" @if ($document->status === 'fetching') wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('documents.index')" :back-label="__('Documents')" :title="$document->title" />

    <x-pages::fields :fields="[
        __('Source') => $document->updateEntry->source->name,
        __('Update') => $document->updateEntry->title,
        __('URL') => $document->url,
        __('Format') => strtoupper((string) $document->format),
        __('Fetched at') => $document->fetched_at?->format('Y-m-d H:i'),
    ]" />

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <x-pages::status :status="$document->status" />
        <flux:text class="flex-1">{{ $document->status_message ?? '—' }}</flux:text>
        @if ($document->original_path)
            <a href="{{ route('documents.original', $document) }}" class="text-sm underline">{{ __('Original') }} ↓</a>
        @endif
        <flux:button wire:click="fetch" size="sm" icon="arrow-path">{{ __('Fetch again') }}</flux:button>
    </div>

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
