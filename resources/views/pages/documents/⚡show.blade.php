<?php

use App\Jobs\ExtractMaterial;
use App\Jobs\FetchDocument;
use App\Models\Document;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 文書 (Document) detail: where it came from, how the fetch went, the original, the Markdown, and the material extracted from it.
new #[Title('文書')] class extends Component {
    public Document $document;

    // Queue the fetch again (after a failure, or after the source's document settings changed).
    public function fetch(): void
    {
        FetchDocument::queueFor($this->document->updateEntry);
        $this->document->refresh();

        Flux::toast(variant: 'success', text: __('Document queued.'));
    }

    // Stage 2.3: queue the extraction of the material (again, if it already ran).
    public function extract(): void
    {
        ExtractMaterial::queueFor($this->document);
        $this->document->refresh();

        Flux::toast(variant: 'success', text: __('Material queued.'));
    }

    // Polled while a background job runs so the screen follows it.
    public function refreshStatus(): void
    {
        $this->document->refresh();
    }
}; ?>

<section class="w-full space-y-6" @if ($document->status === 'fetching' || $document->material?->status === 'extracting') wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('documents.index')" :back-label="__('Documents')" :title="$document->title" />

    <x-pages::fields :fields="[
        __('Source') => $document->updateEntry->source->name,
        __('Update') => $document->updateEntry->title,
        __('URL') => $document->url,
        __('Format') => strtoupper((string) $document->format),
        __('Fetched at') => $document->fetched_at?->display(),
    ]" />

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <x-pages::status :status="$document->status" />
        <flux:text class="flex-1">{{ $document->status_message ?? '—' }}</flux:text>
        @if ($document->original_path)
            <a href="{{ route('documents.original', $document) }}" class="text-sm underline">{{ __('Original') }} ↓</a>
        @endif
        <flux:button wire:click="fetch" size="sm" icon="arrow-path">{{ __('Fetch again') }}</flux:button>
    </div>

    <flux:heading size="lg">{{ __('Material') }}</flux:heading>
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        @if ($document->material)
            <x-pages::status :status="$document->material->status" />
            <flux:text class="flex-1">
                <a href="{{ route('materials.show', $document->material) }}" class="underline" wire:navigate>{{ __('Open') }}</a>
                @if ($document->material->status_message)
                    — {{ $document->material->status_message }}
                @endif
            </flux:text>
        @else
            <flux:text class="flex-1">{{ __('Not extracted yet.') }}</flux:text>
        @endif
        <flux:button wire:click="extract" size="sm" icon="cube">{{ __('Extract material') }}</flux:button>
    </div>

    <flux:heading size="lg">{{ __('Markdown') }}</flux:heading>
    <pre class="max-h-96 overflow-auto rounded-xl border border-neutral-200 p-4 text-sm whitespace-pre-wrap dark:border-neutral-700">{{ $document->markdown ?? __('Not fetched yet.') }}</pre>
</section>
