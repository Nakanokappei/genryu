<?php

use App\Jobs\ExtractMaterial;
use App\Models\Material;
use App\Models\UpdateEntry;
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
        return Material::query()->with('updateEntry.source')->withCount('articles')->latest()->get();
    }

    // Stage 2.3: queue the extraction for every fetched document whose material is missing or failed.
    public function extract(): void
    {
        $entries = UpdateEntry::query()->where('status', 'fetched')->whereDoesntHave('material', fn ($query) => $query->whereIn('status', ['extracting', 'extracted']))->get();
        $entries->each(fn (UpdateEntry $entry) => ExtractMaterial::queueFor($entry));
        unset($this->materials);

        Flux::toast(variant: 'success', text: __(':count materials queued.', ['count' => $entries->count()]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->materials->contains('status', 'extracting')) wire:poll.5s @endif>
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="xl">{{ __('Materials') }}</flux:heading>
        <flux:button wire:click="extract" class="ms-auto" icon="cube">{{ __('Extract materials') }}</flux:button>
    </div>

    <x-pages::table :columns="[__('Update'), __('Source'), __('Status'), __('Data'), __('Articles'), __('Created')]" :empty="$this->materials->isEmpty()">
        @foreach ($this->materials as $material)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('materials.show', $material) }}" class="underline" wire:navigate>{{ $material->updateEntry->title }}</a></td>
                <td class="px-3 py-2"><x-pages::source-name :source="$material->updateEntry->source" /></td>
                <td class="px-3 py-2"><x-pages::status :status="$material->status" /></td>
                <td class="max-w-xl truncate px-3 py-2 text-neutral-500">{{ $material->data !== null ? json_encode($material->dataInPolicyOrder(), JSON_UNESCAPED_UNICODE) : ($material->status_message ?? '—') }}</td>
                <td class="px-3 py-2">{{ $material->articles_count }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $material->created_at->display() }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
