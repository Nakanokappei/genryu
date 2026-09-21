<?php

use App\Jobs\FetchDocument;
use App\Models\UpdateEntry;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 更新情報 (Update) detail: the list item and the document fetched for it.
new #[Title('更新情報')] class extends Component {
    public UpdateEntry $updateEntry;

    // Stage 2.2: queue the fetch of the page behind this entry (again, if it already ran).
    public function fetchDocument(): void
    {
        FetchDocument::queueFor($this->updateEntry);
        $this->updateEntry->refresh();

        Flux::toast(variant: 'success', text: __('Document queued.'));
    }

    // Polled while fetching so the screen follows the background job.
    public function refreshStatus(): void
    {
        $this->updateEntry->refresh();
    }
}; ?>

<section class="w-full space-y-6" @if ($updateEntry->document?->status === 'fetching') wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('updates.index')" :back-label="__('Updates')" :title="$updateEntry->title" />

    <x-pages::fields :fields="[
        __('Source') => $updateEntry->source->name,
        __('URL') => $updateEntry->url,
        __('Published at') => $updateEntry->published_at?->format('Y-m-d'),
        __('Created') => $updateEntry->created_at->display(),
    ]" />

    <flux:heading size="lg">{{ __('Document') }}</flux:heading>
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        @if ($updateEntry->document)
            <x-pages::status :status="$updateEntry->document->status" />
            <flux:text class="flex-1">
                <a href="{{ route('documents.show', $updateEntry->document) }}" class="underline" wire:navigate>{{ $updateEntry->document->title }}</a>
                @if ($updateEntry->document->status_message)
                    — {{ $updateEntry->document->status_message }}
                @endif
            </flux:text>
        @else
            <flux:text class="flex-1">{{ __('Not fetched yet.') }}</flux:text>
        @endif
        <flux:button wire:click="fetchDocument" size="sm" icon="arrow-path">{{ __('Fetch document') }}</flux:button>
    </div>
</section>
