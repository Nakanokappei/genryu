<?php

use App\Jobs\ExtractMaterial;
use App\Jobs\FetchDocument;
use App\Models\Document;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 文書 (Document) detail: where it was listed, how the fetch went, the original, the Markdown, and the material extracted from it.
new #[Title('文書')] class extends Component {
    public Document $document;

    // Stage 2.2: queue the fetch of this document (again, if it already ran).
    public function fetchDocument(): void
    {
        FetchDocument::queueFor($this->document);
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
    <x-pages::detail-header :back="route('documents.index')" :back-label="__('Documents')" :source="$document->source" :title="$document->title" />

    <x-pages::fields :fields="[
        __('URL') => $document->url,
        __('Published at') => $document->published_at?->format('Y-m-d'),
        __('Format') => strtoupper((string) $document->format),
        __('Fetched at') => $document->fetched_at?->display(),
        __('Created') => $document->created_at->display(),
    ]" />

    <flux:heading size="lg">{{ __('Document') }}</flux:heading>
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        @if ($document->excluded_by !== null)
            <x-pages::status status="excluded" />
            <flux:text class="flex-1">{{ __('Excluded by keyword: :keyword', ['keyword' => $document->excluded_by]) }}</flux:text>
        @elseif ($document->status !== null)
            <x-pages::status :status="$document->status" />
            <flux:text class="flex-1">{{ $document->status_message ?? '—' }}</flux:text>
        @else
            <flux:text class="flex-1">{{ __('Not fetched yet.') }}</flux:text>
        @endif
        @if ($document->original_path)
            <a href="{{ route('documents.original', $document) }}" class="text-sm underline">{{ __('Original') }} ↓</a>
        @endif
        <flux:button wire:click="fetchDocument" size="sm" icon="arrow-path">{{ $document->status === null ? __('Fetch document') : __('Fetch again') }}</flux:button>
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
