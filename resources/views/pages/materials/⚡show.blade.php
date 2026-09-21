<?php

use App\Jobs\ExtractMaterial;
use App\Jobs\GenerateArticle;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 素材情報 (Material) detail: how the extraction went, the JSON, and the articles generated from it.
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
    <x-pages::detail-header :back="route('materials.index')" :back-label="__('Materials')" :title="$material->document->title" />

    <x-pages::fields :fields="[
        __('Source') => $material->document->updateEntry->source->name,
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

    <flux:heading size="lg">{{ __('Data') }}</flux:heading>
    <pre class="max-h-[32rem] overflow-auto rounded-xl border border-neutral-200 p-4 text-sm whitespace-pre-wrap dark:border-neutral-700">{{ $material->data !== null ? json_encode($material->dataInPolicyOrder(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : __('Not extracted yet.') }}</pre>

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
