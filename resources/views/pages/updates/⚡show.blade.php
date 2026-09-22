<?php

use App\Jobs\ExtractMaterial;
use App\Jobs\FetchDocument;
use App\Models\UpdateEntry;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 更新情報 (Update) detail: the list item, how the fetch of its document went, the original, the Markdown, and the material extracted from it.
new #[Title('更新情報')] class extends Component {
    public UpdateEntry $updateEntry;

    // Stage 2.2: queue the fetch of the page behind this entry (again, if it already ran).
    public function fetchDocument(): void
    {
        FetchDocument::queueFor($this->updateEntry);
        $this->updateEntry->refresh();

        Flux::toast(variant: 'success', text: __('Document queued.'));
    }

    // Stage 2.3: queue the extraction of the material (again, if it already ran).
    public function extract(): void
    {
        ExtractMaterial::queueFor($this->updateEntry);
        $this->updateEntry->refresh();

        Flux::toast(variant: 'success', text: __('Material queued.'));
    }

    // Polled while a background job runs so the screen follows it.
    public function refreshStatus(): void
    {
        $this->updateEntry->refresh();
    }
}; ?>

<section class="w-full space-y-6" @if ($updateEntry->status === 'fetching' || $updateEntry->material?->status === 'extracting') wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('updates.index')" :back-label="__('Updates')" :source="$updateEntry->source" :title="$updateEntry->title" />

    <x-pages::fields :fields="[
        __('URL') => $updateEntry->url,
        __('Published at') => $updateEntry->published_at?->format('Y-m-d'),
        __('Format') => strtoupper((string) $updateEntry->format),
        __('Fetched at') => $updateEntry->fetched_at?->display(),
        __('Created') => $updateEntry->created_at->display(),
    ]" />

    <flux:heading size="lg">{{ __('Document') }}</flux:heading>
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        @if ($updateEntry->excluded_by !== null)
            <x-pages::status status="excluded" />
            <flux:text class="flex-1">{{ __('Excluded by keyword: :keyword', ['keyword' => $updateEntry->excluded_by]) }}</flux:text>
        @elseif ($updateEntry->status !== null)
            <x-pages::status :status="$updateEntry->status" />
            <flux:text class="flex-1">{{ $updateEntry->status_message ?? '—' }}</flux:text>
        @else
            <flux:text class="flex-1">{{ __('Not fetched yet.') }}</flux:text>
        @endif
        @if ($updateEntry->original_path)
            <a href="{{ route('updates.original', $updateEntry) }}" class="text-sm underline">{{ __('Original') }} ↓</a>
        @endif
        <flux:button wire:click="fetchDocument" size="sm" icon="arrow-path">{{ $updateEntry->status === null ? __('Fetch document') : __('Fetch again') }}</flux:button>
    </div>

    <flux:heading size="lg">{{ __('Material') }}</flux:heading>
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        @if ($updateEntry->material)
            <x-pages::status :status="$updateEntry->material->status" />
            <flux:text class="flex-1">
                <a href="{{ route('materials.show', $updateEntry->material) }}" class="underline" wire:navigate>{{ __('Open') }}</a>
                @if ($updateEntry->material->status_message)
                    — {{ $updateEntry->material->status_message }}
                @endif
            </flux:text>
        @else
            <flux:text class="flex-1">{{ __('Not extracted yet.') }}</flux:text>
        @endif
        <flux:button wire:click="extract" size="sm" icon="cube">{{ __('Extract material') }}</flux:button>
    </div>

    <flux:heading size="lg">{{ __('Markdown') }}</flux:heading>
    <pre class="max-h-96 overflow-auto rounded-xl border border-neutral-200 p-4 text-sm whitespace-pre-wrap dark:border-neutral-700">{{ $updateEntry->markdown ?? __('Not fetched yet.') }}</pre>
</section>
