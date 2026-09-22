<?php

use App\Jobs\ExtractMaterial;
use App\Jobs\FetchDocument;
use App\Jobs\ScreenDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 文書 (Document) detail: where it was listed, how the fetch went, the original, the screening and its decision, the Markdown, and the material extracted from it.
new #[Title('文書')] class extends Component {
    public Document $document;

    /** The model to screen with from here: the one chosen for the content filtering, or, for a document sent to review, the next model up. */
    public string $screeningModel = '';

    public function mount(): void
    {
        $model = EditorialPolicy::modelFor('content_filtering');
        $this->screeningModel = $this->document->screening?->decision === 'review' ? EditorialPolicy::nextModelUp($model) : $model;
    }

    // The gate: queue the screening of this document (again, if it already ran) with the model chosen here.
    public function screen(): void
    {
        $this->validate(['screeningModel' => ['required', 'in:'.implode(',', array_keys(EditorialPolicy::MODELS))]]);
        ScreenDocument::queueFor($this->document, $this->screeningModel);
        $this->document->refresh();

        Flux::toast(variant: 'success', text: __('Screening queued.'));
    }

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

<section class="w-full space-y-6" @if ($document->status === 'fetching' || $document->screening?->status === 'screening' || $document->material?->status === 'extracting') wire:poll.5s="refreshStatus" @endif>
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

    <flux:heading size="lg">{{ __('Screening') }}</flux:heading>
    <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <div class="flex flex-wrap items-center gap-3">
            <x-pages::decision :screening="$document->screening" />
            <flux:text class="flex-1">
                @if ($document->screening === null)
                    {{ __('Not screened yet.') }}
                @elseif ($document->screening->status === 'screened')
                    {{ $document->screening->primary_reason }} — {{ $document->screening->reason }}
                @else
                    {{ $document->screening->status_message ?? '—' }}
                @endif
            </flux:text>
            <flux:select wire:model="screeningModel" size="sm" class="w-56!">
                @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                    <flux:select.option value="{{ $id }}">{{ $model['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button wire:click="screen" size="sm" icon="scale">{{ $document->screening === null ? __('Screen') : __('Screen again') }}</flux:button>
        </div>
        @if ($document->screening?->status === 'screened')
            <flux:text size="sm"><span class="text-neutral-500">{{ __('Evidence') }}:</span> {{ $document->screening->evidence }}</flux:text>
            <flux:text size="sm" class="text-neutral-500">
                {{ $document->screening->model }} / {{ __('Prompt version') }} v{{ $document->screening->prompt->version }} /
                {{ __('Tokens') }}: {{ __('input') }} {{ number_format((int) $document->screening->input_tokens) }}（{{ __('cached') }} {{ number_format((int) $document->screening->cached_tokens) }}, {{ __('cache write') }} {{ number_format((int) $document->screening->cache_write_tokens) }}）, {{ __('output') }} {{ number_format((int) $document->screening->output_tokens) }} /
                {{ number_format((int) $document->screening->latency_ms) }} ms /
                {{ $document->screening->estimated_total_cost !== null ? '$'.number_format($document->screening->estimated_total_cost, 5) : __('cost unknown') }} /
                {{ $document->screening->created_at->display() }}
            </flux:text>
        @endif
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
        @if ($document->isRejected())
            <flux:text size="sm" class="text-neutral-500">{{ __('The screening rejected this document.') }}</flux:text>
        @else
            <flux:button wire:click="extract" size="sm" icon="cube">{{ __('Extract material') }}</flux:button>
        @endif
    </div>

    <flux:heading size="lg">{{ __('Markdown') }}</flux:heading>
    <pre class="max-h-96 overflow-auto rounded-xl border border-neutral-200 p-4 text-sm whitespace-pre-wrap dark:border-neutral-700">{{ $document->markdown ?? __('Not fetched yet.') }}</pre>
</section>
