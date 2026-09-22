<?php

use App\Jobs\ExtractMaterial;
use App\Jobs\GenerateArticle;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 素材情報 (Material) detail: how the extraction went, the material section by section (evidence with its quotes, transition, engineering, angles), and the articles generated from it.
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

    @php $data = $material->data; $claims = $material->claims(); $spans = $material->spans(); @endphp

    @if ($data === null)
        <flux:text>{{ __('Not extracted yet.') }}</flux:text>
    @else
        {{-- The material, section by section: the evidence, the transition, the engineering, the angles; the JSON itself at the end. --}}
        <flux:heading size="lg">{{ __('Primary evidence') }}</flux:heading>
        <div class="space-y-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            @foreach (['what_happened' => __('What happened'), 'key_facts' => __('Key facts'), 'reported_claims' => __('Reported claims'), 'numbers' => __('Numbers'), 'actors' => __('Actors')] as $key => $label)
                <x-pages::material-facet :label="$label" :facet="$data['primary_evidence'][$key] ?? []" :claims="$claims" :spans="$spans" />
            @endforeach
        </div>

        @php $transition = $data['technology_transition'] ?? []; @endphp
        <flux:heading size="lg">{{ __('Technology transition') }}</flux:heading>
        <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:text><span class="text-neutral-500">{{ __('Scope') }}:</span> {{ $transition['scope'] ?? '—' }}</flux:text>
            <flux:text>
                <flux:badge size="sm">{{ __($transition['previous_state'] ?? 'unknown') }}</flux:badge> → <flux:badge size="sm">{{ __($transition['current_state'] ?? 'unknown') }}</flux:badge>
                <flux:badge size="sm" :color="match ($transition['assessment'] ?? '') { 'observed_transition' => 'green', 'no_observed_transition' => 'zinc', default => 'amber' }">{{ __($transition['assessment'] ?? 'insufficient_evidence') }}</flux:badge>
            </flux:text>
            @foreach ($transition['assessment_claim_ids'] ?? [] as $id)
                <x-pages::material-claim :claim="$claims[$id] ?? null" :id="$id" :claims="$claims" :spans="$spans" />
            @endforeach
            <x-pages::material-facet :label="__('Frontier transition')" :facet="$transition['frontier_transition'] ?? []" :claims="$claims" :spans="$spans" />
        </div>

        <flux:heading size="lg">{{ __('Engineering') }}</flux:heading>
        <div class="space-y-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            @foreach (['capability' => __('Capability'), 'mechanism' => __('Mechanism'), 'engineering_attack' => __('Engineering attack'), 'capital_commitment' => __('Capital commitment'), 'bottleneck' => __('Bottleneck'), 'industrialization' => __('Industrialization')] as $key => $label)
                <x-pages::material-facet :label="$label" :facet="$data['engineering'][$key] ?? []" :claims="$claims" :spans="$spans" />
            @endforeach
        </div>

        @php $editorial = $data['editorial'] ?? []; @endphp
        <flux:heading size="lg">{{ __('Editorial') }}</flux:heading>
        <div class="space-y-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <x-pages::material-facet :label="__('Why it matters')" :facet="$editorial['why_it_matters'] ?? []" :claims="$claims" :spans="$spans" />

            <div class="space-y-1">
                <flux:subheading>{{ __('Tensions') }}</flux:subheading>
                @forelse ($editorial['tensions'] ?? [] as $tension)
                    <flux:text size="sm"><span class="font-mono text-xs text-neutral-400">{{ $tension['id'] }}</span> <flux:badge size="sm">{{ $tension['axis'] }}</flux:badge> <flux:badge size="sm" color="zinc">{{ __($tension['status']) }}</flux:badge>
                        {{ collect($tension['left_claim_ids'])->map(fn ($id) => $claims[$id]['statement'] ?? $id)->implode(' / ') }} ⟷ {{ collect($tension['right_claim_ids'])->map(fn ($id) => $claims[$id]['statement'] ?? $id)->implode(' / ') }}
                        — {{ collect($tension['relationship_claim_ids'])->map(fn ($id) => $claims[$id]['statement'] ?? $id)->implode(' / ') }}</flux:text>
                @empty
                    <flux:text size="sm" class="text-neutral-500">—</flux:text>
                @endforelse
            </div>

            <div class="space-y-2">
                <flux:subheading>{{ __('Possible angles') }}</flux:subheading>
                @forelse ($editorial['possible_angles'] ?? [] as $angle)
                    <div class="rounded-lg border p-2 text-sm {{ ($editorial['recommended_angle_id'] ?? null) === $angle['id'] ? 'border-green-400 dark:border-green-600' : 'border-neutral-200 dark:border-neutral-700' }}">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="font-mono text-xs text-neutral-400">{{ $angle['id'] }}</span>
                            <span class="font-medium">{{ $angle['angle'] }}</span>
                            <flux:badge size="sm" :color="match ($angle['decision']) { 'candidate' => 'green', 'hold' => 'amber', default => 'red' }">{{ __($angle['decision']) }}</flux:badge>
                            <flux:badge size="sm">{{ $angle['entry_point'] }}</flux:badge>
                            <span class="text-xs text-neutral-500">{{ implode(', ', $angle['lenses']) }} / {{ __('strength') }} {{ __($angle['strength']) }}</span>
                            @if (($editorial['recommended_angle_id'] ?? null) === $angle['id'])
                                <flux:badge size="sm" color="green" icon="star">{{ __('recommended') }}</flux:badge>
                            @endif
                        </div>
                        <flux:text size="sm"><span class="text-neutral-500">{{ __('Reader question') }}:</span> {{ $angle['reader_question'] }}</flux:text>
                        <flux:text size="sm"><span class="text-neutral-500">{{ __('Why now') }}:</span> {{ collect($angle['why_now_primary_claim_ids'])->map(fn ($id) => $claims[$id]['statement'] ?? $id)->implode(' / ') }}</flux:text>
                        @if ($angle['counterpoint_claim_ids'] !== [])
                            <flux:text size="sm"><span class="text-neutral-500">{{ __('Counterpoints') }}:</span> {{ collect($angle['counterpoint_claim_ids'])->map(fn ($id) => $claims[$id]['statement'] ?? $id)->implode(' / ') }}</flux:text>
                        @endif
                        <flux:text size="sm" class="text-neutral-500">{{ $angle['decision_reason'] }}</flux:text>
                    </div>
                @empty
                    <flux:text size="sm" class="text-neutral-500">—</flux:text>
                @endforelse
                @if (($editorial['recommendation_reason'] ?? '') !== '')
                    <flux:text size="sm"><span class="text-neutral-500">{{ __('Recommendation') }}:</span> {{ $editorial['recommendation_reason'] }}</flux:text>
                @endif
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="space-y-1">
                    <flux:subheading>{{ __('Missing information') }}</flux:subheading>
                    @forelse ($editorial['missing_information'] ?? [] as $gap)
                        <flux:text size="sm"><span class="font-mono text-xs text-neutral-400">{{ $gap['id'] }}</span> {{ $gap['question'] }} <span class="text-neutral-500">— {{ $gap['why_it_matters'] }}</span></flux:text>
                    @empty
                        <flux:text size="sm" class="text-neutral-500">—</flux:text>
                    @endforelse
                </div>
                <div class="space-y-1">
                    <flux:subheading>{{ __('Next signals') }}</flux:subheading>
                    @forelse ($editorial['next_signals'] ?? [] as $signal)
                        <flux:text size="sm"><span class="font-mono text-xs text-neutral-400">{{ $signal['id'] }}</span> {{ $signal['signal'] }} <span class="text-neutral-500">— {{ $signal['observable_criterion'] }} → {{ __($signal['target_state']) }}</span></flux:text>
                    @empty
                        <flux:text size="sm" class="text-neutral-500">—</flux:text>
                    @endforelse
                </div>
            </div>
        </div>

        <flux:text size="sm" class="text-neutral-500">
            {{ $material->model }} / {{ __('Prompt version') }} v{{ $material->prompt?->version ?? '—' }} / {{ __('Revision') }} #{{ $material->document_revision_id ?? '—' }} /
            {{ __('Tokens') }}: {{ __('input') }} {{ number_format((int) $material->input_tokens) }}（{{ __('cached') }} {{ number_format((int) $material->cached_tokens) }}, {{ __('cache write') }} {{ number_format((int) $material->cache_write_tokens) }}）, {{ __('output') }} {{ number_format((int) $material->output_tokens) }} /
            {{ number_format((int) $material->latency_ms) }} ms / {{ $material->estimated_total_cost !== null ? '$'.number_format($material->estimated_total_cost, 5) : __('cost unknown') }}
        </flux:text>

        <details class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <summary class="cursor-pointer text-sm text-neutral-500">{{ __('Data') }} (JSON)</summary>
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
