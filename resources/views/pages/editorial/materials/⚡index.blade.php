<?php

use App\Jobs\ExtractMaterial;
use App\Livewire\PagedList;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// 素材情報 (Materials): the structuring layer of the editorial policy (the developer prompt and the model of the analyst that reads an adopted document and writes the parts an article is made of), and the material extracted from each adopted document in the background; nothing is added by hand here.
new #[Title('素材情報')] class extends PagedList {
    // Structuring: the developer prompt (OpenAI's name for the system prompt) of the analyst.
    public string $structuring = '';

    /** The model the material is built with (UI: 素材情報のモデル), one of EditorialPolicy::TEXT_MODELS. */
    public string $structuringModel = EditorialPolicy::DEFAULT_MODEL;

    public function mount(): void
    {
        $this->structuring = EditorialPolicy::bodyFor('structuring');
        $this->structuringModel = EditorialPolicy::modelFor('structuring');
    }

    public function saveStructuring(): void
    {
        $this->validate(['structuringModel' => EditorialPolicy::modelRule()]);
        EditorialPolicy::query()->updateOrCreate(['layer' => 'structuring'], ['body' => $this->structuring, 'model' => $this->structuringModel]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Material> */
    #[Computed]
    public function materials()
    {
        // Ordered by created_at then id so the pages never overlap, as the other list screens are.
        return Material::query()->with('document.source')->withCount('articles')->latest()->latest('id')->paginate($this->rowsPerPage());
    }

    // Stage 2.3: queue the extraction for every adopted document whose material is missing or failed.
    public function extract(): void
    {
        // Only the adopted documents (by a person, else by the screening) go on to the detailed analysis in bulk; a single document can still be extracted from its own screen unless rejected.
        $documents = Document::query()->where('status', 'fetched')->decidedAs('adopt')->whereDoesntHave('material', fn ($query) => $query->whereIn('status', ['extracting', 'extracted']))->get();
        $documents->each(fn (Document $document) => ExtractMaterial::queueFor($document));
        unset($this->materials);

        Flux::toast(variant: 'success', text: __(':count materials queued.', ['count' => $documents->count()]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->materials->contains('status', 'extracting')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Materials') }}</flux:heading>

    {{-- Structuring sits with the materials because it is what makes them: the criteria the analyst reads a document by. --}}
    <form wire:submit="saveStructuring" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Structuring') }}</flux:heading>
        <flux:text>{{ __('The developer prompt and the model of the analyst: an LLM reads an adopted document and writes the parts an article is made of: the angle it would be written on, what was true before, what this document changes, what may follow, the facts the document gives, the background it fills in from its own general knowledge, and what it infers from both: who gains, who loses, and what everyday life looks like if this holds. What it cannot write plainly it leaves out. The prompt is the same for every document and is served from the cache; a changed prompt is a new version, pinned by every material.') }}</flux:text>
        <flux:textarea wire:model="structuring" :label="__('Developer prompt (editable)')" rows="12" class="font-mono text-xs" />
        <x-pages::fixed-prompts :instruction="\App\Actions\ProposeMaterial::INSTRUCTIONS" :input="[__('The document, as Markdown')]" />
        <x-pages::model-select wire:model="structuringModel" :label="__('Model of the structuring')" class="max-w-xl" />
        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="extract" icon="cube" wire:confirm="{{ __('Extract a material from every adopted document that has none? Each one is one call to the model.') }}">{{ __('Extract materials from adopted documents') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Document'), __('Status'), __('Articles'), __('Created')]" :empty="$this->materials->isEmpty()">
        @foreach ($this->materials as $material)
            <tr>
                <td class="px-3 py-2"><x-pages::favicon :source="$material->document->source" /> <a href="{{ route('editorial.materials.show', $material) }}" class="underline" wire:navigate>{{ $material->document->title }}</a></td>
                <td class="px-3 py-2"><x-pages::status :status="$material->status" /> <span class="text-neutral-500">{{ $material->data === null ? $material->status_message : '' }}</span></td>
                <td class="px-3 py-2">{{ $material->articles_count }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-neutral-500">{{ $material->created_at->display() }}</td>
            </tr>
        @endforeach
    </x-pages::table>

    <x-pages::pagination :paginator="$this->materials" />
</section>
