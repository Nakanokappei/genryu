<?php

use App\Jobs\ExtractMaterial;
use App\Jobs\GenerateArticle;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 素材情報 (Material) detail.
new #[Title('素材情報')] class extends Component {
    public Material $material;

    /** UI: テキスト / JSON */
    public string $view = 'text';

    // Queue the extraction again.
    public function extract(): void
    {
        ExtractMaterial::queueFor($this->material->document);
        $this->material->refresh();

        Flux::toast(variant: 'success', text: __('Material queued.'));
    }

    // Queue the article.
    public function generate(): void
    {
        GenerateArticle::queueFor($this->material);
        $this->material->refresh();

        Flux::toast(variant: 'success', text: __('Article queued.'));
    }

    // Polled while a job runs.
    public function refreshStatus(): void
    {
        $this->material->refresh();
    }
}; ?>

<section class="w-full space-y-6" @if ($material->status === 'extracting' || $material->articles->contains('status', 'generating')) wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('editorial.materials.index')" :back-label="__('Materials')" :source="$material->document->source" :title="$material->document->title" />

    <x-pages::fields :fields="[
        __('Document') => $material->document->title,
        __('URL') => $material->document->url,
        __('Created') => $material->created_at->display(),
    ]" />

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <x-pages::status :status="$material->status" />
        <flux:text class="flex-1">{{ $material->status_message ?? '—' }}</flux:text>
        <a href="{{ route('editorial.documents.show', $material->document) }}" class="text-sm underline" wire:navigate>{{ __('Document') }}</a>
        <flux:button wire:click="extract" size="sm" icon="arrow-path">{{ __('Extract again') }}</flux:button>
    </div>

    @if ($material->parts === null)
        <flux:text>{{ __('Not extracted yet.') }}</flux:text>
    @else
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="lg">{{ __('Material') }}</flux:heading>
            <flux:radio.group wire:model.live="view" variant="segmented" size="sm" class="ms-auto">
                <flux:radio value="text" :label="__('Text')" />
                <flux:radio value="json" :label="__('JSON')" />
            </flux:radio.group>
        </div>
        <div class="space-y-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            @if ($view === 'json')
                <pre class="overflow-auto text-sm whitespace-pre-wrap">{{ json_encode([...$material->parts(), ...($material->figures() === [] ? [] : ['figures' => $material->figures()])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
                @foreach ($material->parts() as $part => $value)
                    <div class="space-y-1">
                        <flux:subheading>{{ __($part) }}</flux:subheading>
                        @if (is_array($value))
                            <ul class="list-disc ps-5 text-sm">@foreach ($value as $line)<li>{{ $line }}</li>@endforeach</ul>
                        @else
                            <flux:text size="sm" @class(['font-medium' => $part === 'angle'])>{{ $value }}</flux:text>
                        @endif
                    </div>
                @endforeach

                {{-- 図版, each linking to the image. --}}
                @if ($material->figures() !== [])
                    <div class="space-y-2">
                        <flux:subheading>{{ __('figures') }}</flux:subheading>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach ($material->figures() as $figure)
                                <a href="{{ $figure['url'] }}" target="_blank" rel="noopener noreferrer" class="space-y-1 text-xs">
                                    <img src="{{ $figure['url'] }}" alt="{{ $figure['alt'] }}" loading="lazy" referrerpolicy="no-referrer" class="aspect-video w-full rounded-md border border-neutral-200 object-contain dark:border-neutral-700">
                                    <span class="block text-neutral-500">{{ $figure['caption'] ?? $figure['alt'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>

        @php $counts = $material->counts(); @endphp
        <flux:text size="sm" class="text-neutral-500">
            {{ __('primary_source') }} {{ $counts['primary_source'] }} / {{ __('general_knowledge') }} {{ $counts['general_knowledge'] }} / {{ __('inference') }} {{ $counts['inference'] }} —
            {{ $material->model }} / {{ __('Prompt version') }} v{{ $material->prompt?->version ?? '—' }} / {{ __('Revision') }} #{{ $material->document_revision_id ?? '—' }} /
            {{ __('Tokens') }}: {{ __('input') }} {{ number_format((int) $material->input_tokens) }}（{{ __('cached') }} {{ number_format((int) $material->cached_tokens) }}）, {{ __('output') }} {{ number_format((int) $material->output_tokens) }} /
            {{ number_format((int) $material->latency_ms) }} ms / {{ $material->estimated_total_cost !== null ? '$'.number_format($material->estimated_total_cost, 5) : __('cost unknown') }}
        </flux:text>

    @endif

    @if (($material->failed_checks ?? []) !== [])
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('The checks the last extraction failed') }}</flux:callout.heading>
            <flux:callout.text><ul class="list-disc ps-4">@foreach ($material->failed_checks as $error)<li>{{ $error }}</li>@endforeach</ul></flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="lg">{{ __('Articles') }}</flux:heading>
        <flux:button wire:click="generate" class="ms-auto" size="sm" icon="pencil-square">{{ __('Generate article') }}</flux:button>
    </div>
    <x-pages::table :columns="[__('Title'), __('Status'), __('Published at')]" :empty="$material->articles->isEmpty()">
        @foreach ($material->articles as $article)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('editorial.articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayHeadline() }}</a></td>
                <td class="px-3 py-2"><x-pages::status :status="$article->status" /> <span class="text-neutral-500">{{ $article->status_message }}</span></td>
                <td class="px-3 py-2 text-neutral-500">{{ $article->published_at?->display() ?? __('Not published.') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
