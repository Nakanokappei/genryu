<?php

use App\Actions\MeasureLikeness;
use App\Jobs\ApplySemanticFilter;
use App\Jobs\ExtractMaterial;
use App\Jobs\FetchDocument;
use App\Jobs\ScreenDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\SemanticFilterExample;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 文書 (Document) detail.
new #[Title('文書')] class extends Component {
    public Document $document;

    /** The model to screen with from here. */
    public string $screeningModel = '';

    /** UI: 人の判定 — adopt / reject */
    public string $humanDecision = '';

    public string $humanReason = '';

    // Preselect the screening model and fill the human decision.
    public function mount(): void
    {
        $model = EditorialPolicy::modelFor('content_filtering');
        $this->screeningModel = $this->document->latestScreening?->decision === 'review' ? EditorialPolicy::nextModelUp($model) : $model;
        $this->humanDecision = (string) $this->document->human_decision;
        $this->humanReason = (string) $this->document->human_reason;
    }

    // Save 人の判定.
    public function decide(): void
    {
        $this->validate(['humanDecision' => ['required', 'in:adopt,reject'], 'humanReason' => ['nullable', 'string', 'max:1000']]);
        $this->document->update(['human_decision' => $this->humanDecision, 'human_reason' => $this->humanReason !== '' ? $this->humanReason : null, 'human_decided_at' => now(), 'human_decided_by' => auth()->id()]);

        // Adopted on a feed summary: fetch the full text.
        if ($this->document->wantsFullText()) {
            FetchDocument::queueFor($this->document);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Withdraw 人の判定.
    public function undecide(): void
    {
        $this->document->update(['human_decision' => null, 'human_reason' => null, 'human_decided_at' => null, 'human_decided_by' => null]);
        $this->humanDecision = '';
        $this->humanReason = '';

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Queue the screening with the chosen model.
    public function screen(): void
    {
        $this->validate(['screeningModel' => EditorialPolicy::modelRule()]);
        ScreenDocument::queueFor($this->document, $this->screeningModel);
        $this->document->refresh();

        Flux::toast(variant: 'success', text: __('Screening queued.'));
    }

    // Queue the fetch of the document.
    public function fetchDocument(): void
    {
        FetchDocument::queueFor($this->document);
        $this->document->refresh();

        Flux::toast(variant: 'success', text: __('Document queued.'));
    }

    // Queue the extraction of the material.
    public function extract(): void
    {
        ExtractMaterial::queueFor($this->document);
        $this->document->refresh();

        Flux::toast(variant: 'success', text: __('Material queued.'));
    }

    // Queue the semantic filter for the document.
    public function applySemanticFilter(): void
    {
        ApplySemanticFilter::queueFor($this->document);

        Flux::toast(variant: 'success', text: __('Queued for the semantic filter.'));
    }

    // Mark the document as a like / unlike example, or unmark it, and measure again.
    public function markExample(string $side, MeasureLikeness $measure): void
    {
        if ($side === 'none') {
            $this->document->semanticFilterExample()->delete();
        } else {
            abort_unless(array_key_exists($side, SemanticFilterExample::SIDES), 422);
            $measure($this->document);
            SemanticFilterExample::query()->updateOrCreate(['document_id' => $this->document->id], ['side' => $side, 'created_by' => auth()->id()]);
        }

        // A fresh instance: $measure read the examples before this change.
        $result = app(MeasureLikeness::class)->again();
        $this->document->refresh();

        Flux::toast(variant: 'success', duration: 8000, text: __('Saved. :measured documents measured again, :below below the threshold.', $result));
    }

    // Polled while a job runs.
    public function refreshStatus(): void
    {
        $this->document->refresh();
    }
}; ?>

<section class="w-full space-y-6" @if ($document->status === 'fetching' || $document->latestScreening?->status === 'screening' || $document->material?->status === 'extracting') wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('editorial.documents.index')" :back-label="__('Documents')" :source="$document->source" :title="$document->title" />

    {{-- Format, URL and times. --}}
    <div class="space-y-2 rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
        <dl class="grid gap-x-6 gap-y-2 md:grid-cols-[max-content_1fr]">
            <div class="flex gap-3">
                <dt class="text-neutral-500">{{ __('Format') }}</dt>
                <dd>{{ strtoupper((string) $document->format) ?: '—' }}</dd>
            </div>
            <div class="flex min-w-0 gap-3">
                <dt class="text-neutral-500">{{ __('URL') }}</dt>
                <dd class="min-w-0 break-all">
                    <a href="{{ $document->url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $document->url }}</a>
                </dd>
            </div>
        </dl>
        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-3">
            @foreach ([($document->published_has_time ? __('Published at') : __('Published on')) => $document->publishedDisplay(), __('Fetched at') => $document->fetched_at?->display(), __('Created') => $document->created_at->display()] as $label => $value)
                <div class="flex gap-3">
                    <dt class="text-neutral-500">{{ $label }}</dt>
                    <dd>{{ $value !== null && $value !== '' ? $value : '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

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
            <a href="{{ route('editorial.documents.original', $document) }}" class="text-sm underline">{{ __('Original') }} ↓</a>
        @endif
        <flux:button wire:click="fetchDocument" size="sm" icon="arrow-path">{{ $document->status === null ? __('Fetch document') : __('Fetch again') }}</flux:button>
    </div>

    @if ($document->hasShortBody())
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('The body has only :count characters', ['count' => mb_strlen((string) $document->markdown)]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('The document settings of the source may catch a teaser or a header instead of the body. Compare with the original, then fix the settings on the source and rebuild the Markdown.') }} <a href="{{ route('editorial.sources.show', $document->source) }}" class="underline" wire:navigate>{{ __('Document settings of :source', ['source' => $document->source->name]) }}</a></flux:callout.text>
        </flux:callout>
    @endif

    {{-- 意味フィルタ: likeness, nearest of each side, example mark. --}}
    <flux:heading size="lg">{{ __('Semantic filter') }}</flux:heading>
    <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <div class="flex flex-wrap items-center gap-3">
            @if ($document->likeness === null)
                <flux:text class="flex-1">{{ isset($document->likeness_detail['error']) ? __('The semantic filter could not measure this document: :reason', ['reason' => $document->likeness_detail['error']]) : __('Not measured yet.') }}</flux:text>
            @else
                @if ($document->isLeftOut())
                    <x-pages::status status="left_out" />
                @endif
                <flux:text class="flex-1">{{ __('Likeness') }} <span class="font-medium">{{ sprintf('%+.3f', $document->likeness) }}</span>（{{ __('threshold') }} {{ sprintf('%+.2f', \App\Models\EditorialPolicy::likenessThreshold()) }}）</flux:text>
            @endif
            <flux:button wire:click="applySemanticFilter" size="sm" icon="funnel">{{ $document->likeness === null ? __('Apply the semantic filter') : __('Apply again') }}</flux:button>
        </div>
        @foreach (['like' => __('Nearest "like"'), 'unlike' => __('Nearest "unlike"')] as $side => $label)
            @if (isset($document->likeness_detail[$side]['label']))
                @php([$kind, $exampleId, $exampleTitle] = array_pad(explode(':', $document->likeness_detail[$side]['label'], 3), 3, null))
                <flux:text size="sm"><span class="text-neutral-500">{{ $label }}（{{ number_format($document->likeness_detail[$side]['similarity'], 3) }}）:</span>
                    @if ($kind === 'example')
                        {{ __('Example') }} <a href="{{ route('editorial.documents.show', (int) $exampleId) }}" class="underline" wire:navigate>{{ $exampleTitle }}</a>
                    @else
                        {{ $document->likeness_detail[$side]['label'] }}
                    @endif
                </flux:text>
            @endif
        @endforeach
        <div class="flex flex-wrap items-center gap-3">
            <flux:text size="sm">{{ __('As an example of the semantic filter:') }}</flux:text>
            @foreach ([...\App\Models\SemanticFilterExample::SIDES, 'none' => 'Not an example'] as $side => $label)
                <flux:button wire:click="markExample('{{ $side }}')" size="sm" :variant="($document->semanticFilterExample?->side ?? 'none') === $side ? 'primary' : 'outline'">{{ __($label) }}</flux:button>
            @endforeach
        </div>
        <flux:text size="sm" class="text-neutral-500">{{ __('An example teaches the semantic filter; it does not adopt or reject the document (the human decision below does).') }}</flux:text>
    </div>

    <flux:heading size="lg">{{ __('Screening') }}</flux:heading>
    <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <div class="flex flex-wrap items-center gap-3">
            <x-pages::decision :document="$document" />
            <flux:text class="flex-1">
                @if ($document->latestScreening === null)
                    {{ __('Not screened yet.') }}
                @elseif ($document->latestScreening->status === 'screened')
                    {{ $document->latestScreening->reason_class }} — {{ $document->latestScreening->reason }}
                @else
                    {{ $document->latestScreening->status_message ?? '—' }}
                @endif
            </flux:text>
            <x-pages::model-select wire:model="screeningModel" size="sm" class="w-56!" detail="name" />
            <flux:button wire:click="screen" size="sm" icon="scale">{{ $document->latestScreening === null ? __('Screen') : __('Screen again') }}</flux:button>
        </div>
        @if ($document->latestScreening?->status === 'screened')
            <flux:text size="sm"><span class="text-neutral-500">{{ __('Evidence') }}:</span> {{ $document->latestScreening->evidence }}</flux:text>
            @if ($document->latestScreening->status_message)
                <flux:text size="sm">{{ $document->latestScreening->status_message }}</flux:text>
            @endif
            <flux:text size="sm" class="text-neutral-500">
                {{ $document->latestScreening->model }}（{{ $document->latestScreening->pass === 2 ? __('second pass') : __('first pass') }}）/ {{ __('Prompt version') }} v{{ $document->latestScreening->prompt->version }} /
                {{ __('Tokens') }}: {{ __('input') }} {{ number_format((int) $document->latestScreening->input_tokens) }}（{{ __('cached') }} {{ number_format((int) $document->latestScreening->cached_tokens) }}, {{ __('cache write') }} {{ number_format((int) $document->latestScreening->cache_write_tokens) }}）, {{ __('output') }} {{ number_format((int) $document->latestScreening->output_tokens) }} /
                {{ number_format((int) $document->latestScreening->latency_ms) }} ms /
                {{ $document->latestScreening->estimated_total_cost !== null ? '$'.number_format($document->latestScreening->estimated_total_cost, 5) : __('cost unknown') }} /
                {{ $document->latestScreening->created_at->display() }}
            </flux:text>
        @endif
    </div>

    <flux:heading size="lg">{{ __('Human decision') }}</flux:heading>
    <form wire:submit="decide" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:text>{{ __('Your own verdict on this document. It outranks the screening at the gate, and is kept to teach the screening later.') }}</flux:text>
        <div class="flex flex-wrap items-end gap-3">
            <flux:radio.group wire:model="humanDecision" :label="__('Decision')" variant="segmented" size="sm">
                <flux:radio value="adopt" :label="__('adopt')" />
                <flux:radio value="reject" :label="__('reject')" />
            </flux:radio.group>
            <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
            @if ($document->human_decision !== null)
                <flux:button type="button" wire:click="undecide" size="sm">{{ __('Withdraw') }}</flux:button>
            @endif
        </div>
        <flux:textarea wire:model="humanReason" :label="__('Reason')" rows="3" />
        @if ($document->human_decision !== null)
            <flux:text size="sm" class="text-neutral-500">{{ __('Decided :when by :who', ['when' => $document->human_decided_at?->display(), 'who' => $document->humanDecider?->name ?? '—']) }}</flux:text>
        @endif
    </form>

    <flux:heading size="lg">{{ __('Material') }}</flux:heading>
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        @if ($document->material)
            <x-pages::status :status="$document->material->status" />
            <flux:text class="flex-1">
                <a href="{{ route('editorial.materials.show', $document->material) }}" class="underline" wire:navigate>{{ __('Open') }}</a>
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
