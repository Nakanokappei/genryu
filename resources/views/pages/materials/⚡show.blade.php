<?php

use App\Jobs\ExtractMaterial;
use App\Jobs\GenerateArticle;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 素材情報 (Material) detail: how the extraction went, the angles recommended for an article, the transition of the technology's state, the editorial lenses that hold with the statements behind them, and the articles generated from it.
new #[Title('素材情報')] class extends Component {
    public Material $material;

    // Queue the extraction again (after a failure, or after the editorial policy changed).
    public function extract(): void
    {
        ExtractMaterial::queueFor($this->material->document);
        $this->material->refresh();

        Flux::toast(variant: 'success', text: __('Material queued.'));
    }

    // Stage 2.4: queue the generation of the article (again, if it already ran).
    public function generate(): void
    {
        GenerateArticle::queueFor($this->material);
        $this->material->refresh();

        Flux::toast(variant: 'success', text: __('Article queued.'));
    }

    // Polled while a background job runs so the screen follows it.
    public function refreshStatus(): void
    {
        $this->material->refresh();
    }
}; ?>

<section class="w-full space-y-6" @if ($material->status === 'extracting' || $material->articles->contains('status', 'generating')) wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('materials.index')" :back-label="__('Materials')" :source="$material->document->source" :title="$material->document->title" />

    <x-pages::fields :fields="[
        __('Document') => $material->document->title,
        __('URL') => $material->document->url,
        __('Created') => $material->created_at->display(),
    ]" />

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <x-pages::status :status="$material->status" />
        <flux:text class="flex-1">{{ $material->status_message ?? '—' }}</flux:text>
        <a href="{{ route('documents.show', $material->document) }}" class="text-sm underline" wire:navigate>{{ __('Document') }}</a>
        <flux:button wire:click="extract" size="sm" icon="arrow-path">{{ __('Extract again') }}</flux:button>
    </div>

    @if ($material->data === null)
        <flux:text>{{ __('Not extracted yet.') }}</flux:text>
    @else
        @php $data = $material->data; $types = $material->claimTypes(); @endphp

        {{-- The angles come first: what this material is for is the entry an article could take. --}}
        @if (($data['recommended_angles'] ?? []) !== [])
            <flux:heading size="lg">{{ __('Recommended angles') }}</flux:heading>
            <div class="space-y-4">
                @foreach ($data['recommended_angles'] as $angle)
                    <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm">{{ $angle['rank'] ?? '—' }}</flux:badge>
                            <flux:heading>{{ $angle['angle'] ?? '' }}</flux:heading>
                            @if (($angle['lens'] ?? null) !== null)
                                <flux:text size="sm" class="text-neutral-500">{{ __($angle['lens']) }}</flux:text>
                            @endif
                        </div>
                        @if (($angle['editorial_thesis'] ?? null) !== null)
                            <flux:text size="sm">{{ $angle['editorial_thesis'] }}</flux:text>
                        @endif
                        @if (($angle['why_strong'] ?? null) !== null)
                            <flux:text size="sm" class="text-neutral-500">{{ __('Why this angle is strong') }}: {{ $angle['why_strong'] }}</flux:text>
                        @endif
                        @foreach ($angle['primary_evidence'] ?? [] as $claim)
                            <x-pages::claim :claim="$claim" />
                        @endforeach
                        @if (($angle['uncertainties'] ?? []) !== [])
                            <flux:text size="sm" class="text-neutral-500">{{ __('Uncertainties') }}: <ul class="list-disc ps-5">@foreach ($angle['uncertainties'] as $line)<li>{{ $line }}</li>@endforeach</ul></flux:text>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Where the technology stood and where it stands now, when the answer could tell. --}}
        @if (($data['technology_transition'] ?? []) !== [])
            @php $transition = $data['technology_transition']; @endphp
            <flux:heading size="lg">{{ __('Technology transition') }}</flux:heading>
            <div class="space-y-2 rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                @if (($transition['previous_state'] ?? null) !== null || ($transition['current_state'] ?? null) !== null)
                    <flux:text class="font-medium">{{ $transition['previous_state'] ?? '—' }} → {{ $transition['current_state'] ?? '—' }}</flux:text>
                @endif
                @foreach (['transition', 'what_changed', 'why_it_matters'] as $part)
                    @if (($transition[$part] ?? null) !== null)
                        <div class="md:grid md:grid-cols-[8rem_1fr] md:gap-3">
                            <div class="text-neutral-500">{{ __($part) }}</div>
                            <div>{{ $transition[$part] }}</div>
                        </div>
                    @endif
                @endforeach
                @foreach ($transition['evidence'] ?? [] as $claim)
                    <x-pages::claim :claim="$claim" />
                @endforeach
            </div>
        @endif

        {{-- Only the lenses the analyst could support; the ones it could not are simply not here. --}}
        @if ($material->lenses() !== [])
            <flux:heading size="lg">{{ __('Editorial lenses') }}</flux:heading>
            <div class="space-y-4">
                @foreach ($material->lenses() as $name => $lens)
                    <x-pages::lens :name="$name" :lens="$lens" />
                @endforeach
            </div>
        @endif

        @foreach (['missing_information' => __('Missing information'), 'next_signals' => __('Next signals')] as $key => $label)
            @if (($data[$key] ?? []) !== [])
                <flux:heading size="lg">{{ $label }}</flux:heading>
                <ul class="list-disc rounded-xl border border-neutral-200 p-4 ps-9 text-sm dark:border-neutral-700">@foreach ($data[$key] as $line)<li>{{ $line }}</li>@endforeach</ul>
            @endif
        @endforeach

        <flux:text size="sm" class="text-neutral-500">
            {{ __('Claims') }}: {{ __('primary_source') }} {{ $types['primary_source'] }} / {{ __('general_knowledge') }} {{ $types['general_knowledge'] }} / {{ __('inference') }} {{ $types['inference'] }} —
            {{ $material->model }} / {{ __('Prompt version') }} v{{ $material->prompt?->version ?? '—' }} / {{ __('Revision') }} #{{ $material->document_revision_id ?? '—' }} /
            {{ __('Tokens') }}: {{ __('input') }} {{ number_format((int) $material->input_tokens) }}（{{ __('cached') }} {{ number_format((int) $material->cached_tokens) }}）, {{ __('output') }} {{ number_format((int) $material->output_tokens) }} /
            {{ number_format((int) $material->latency_ms) }} ms / {{ $material->estimated_total_cost !== null ? '$'.number_format($material->estimated_total_cost, 5) : __('cost unknown') }}
        </flux:text>

        <details class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <summary class="cursor-pointer text-sm text-neutral-500">JSON</summary>
            <pre class="mt-2 max-h-[32rem] overflow-auto text-sm whitespace-pre-wrap">{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @endif

    @if (($material->validation ?? []) !== [])
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('The checks the last extraction failed') }}</flux:callout.heading>
            <flux:callout.text><ul class="list-disc ps-4">@foreach ($material->validation as $error)<li>{{ $error }}</li>@endforeach</ul></flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="lg">{{ __('Articles') }}</flux:heading>
        <flux:button wire:click="generate" class="ms-auto" size="sm" icon="pencil-square">{{ __('Generate article') }}</flux:button>
    </div>
    <x-pages::table :columns="[__('Title'), __('Status'), __('Published at')]" :empty="$material->articles->isEmpty()">
        @foreach ($material->articles as $article)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayTitle() }}</a></td>
                <td class="px-3 py-2"><x-pages::status :status="$article->status" /> <span class="text-neutral-500">{{ $article->status_message }}</span></td>
                <td class="px-3 py-2 text-neutral-500">{{ $article->published_at?->display() ?? __('Not published.') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
