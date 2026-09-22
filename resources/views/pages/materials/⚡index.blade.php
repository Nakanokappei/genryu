<?php

use App\Jobs\ExtractMaterial;
use App\Models\Material;
use App\Models\Document;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 素材情報 (Materials): the structure extracted from each fetched document in the background; nothing is added by hand here.
new #[Title('素材情報')] class extends Component {
    /** @return \Illuminate\Database\Eloquent\Collection<int, Material> */
    #[Computed]
    public function materials()
    {
        return Material::query()->with('document.source')->withCount('articles')->latest()->get();
    }

    // Stage 2.3: queue the extraction for every adopted document whose material is missing or failed.
    public function extract(): void
    {
        // Only the adopted documents (by a person, else by the screening) go on to the detailed analysis in bulk; a single document can still be extracted from its own screen unless rejected.
        $documents = Document::query()->where('status', 'fetched')->where(fn ($query) => $query->where('human_decision', 'adopt')->orWhere(fn ($query) => $query->whereNull('human_decision')->whereRelation('screening', 'decision', 'adopt')))->whereDoesntHave('material', fn ($query) => $query->whereIn('status', ['extracting', 'extracted']))->get();
        $documents->each(fn (Document $document) => ExtractMaterial::queueFor($document));
        unset($this->materials);

        Flux::toast(variant: 'success', text: __(':count materials queued.', ['count' => $documents->count()]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->materials->contains('status', 'extracting')) wire:poll.5s @endif>
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="xl">{{ __('Materials') }}</flux:heading>
        <flux:button wire:click="extract" class="ms-auto" icon="cube">{{ __('Extract materials from adopted documents') }}</flux:button>
    </div>

    <x-pages::table :columns="[__('Document'), __('Source'), __('Status'), __('Data'), __('Articles'), __('Created')]" :empty="$this->materials->isEmpty()">
        @foreach ($this->materials as $material)
            <tr>
                <td class="px-3 py-2"><x-pages::favicon :source="$material->document->source" /> <a href="{{ route('materials.show', $material) }}" class="underline" wire:navigate>{{ $material->document->title }}</a></td>
                <td class="px-3 py-2"><a href="{{ route('sources.show', $material->document->source) }}" class="underline" wire:navigate>{{ $material->document->source->name }}</a></td>
                <td class="px-3 py-2"><x-pages::status :status="$material->status" /></td>
                <td class="max-w-xl truncate px-3 py-2 text-neutral-500">{{ $material->data !== null ? json_encode($material->dataInPolicyOrder(), JSON_UNESCAPED_UNICODE) : ($material->status_message ?? '—') }}</td>
                <td class="px-3 py-2">{{ $material->articles_count }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $material->created_at->display() }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
