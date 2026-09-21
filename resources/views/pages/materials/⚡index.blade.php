<?php

use App\Models\Document;
use App\Models\Material;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 素材情報 (Materials): the structure extracted from a document, entered as JSON by hand for now.
new #[Title('素材情報')] class extends Component {
    #[Validate('required|exists:documents,id')]
    public string $document_id = '';

    #[Validate('required|json')]
    public string $data = '';

    /** @return \Illuminate\Database\Eloquent\Collection<int, Material> */
    #[Computed]
    public function materials()
    {
        return Material::query()->with('document')->withCount('articles')->latest()->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Document> */
    #[Computed]
    public function documents()
    {
        return Document::query()->latest()->get();
    }

    public function add(): void
    {
        $validated = $this->validate();
        Material::create(['document_id' => $validated['document_id'], 'data' => json_decode($validated['data'], true)]);
        $this->reset('data');
        unset($this->materials);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Materials') }}</flux:heading>

    <form wire:submit="add" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-4 dark:border-neutral-700">
        <flux:select wire:model="document_id" :label="__('Document')" :placeholder="__('Select')">
            @foreach ($this->documents as $document)
                <flux:select.option value="{{ $document->id }}">{{ $document->title }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="md:col-span-3">
            <flux:textarea wire:model="data" :label="__('Data')" rows="6" placeholder='{"summary": "...", "topics": ["..."]}' />
        </div>
        <div class="flex items-end">
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Document'), __('Data'), __('Articles'), __('Created')]" :empty="$this->materials->isEmpty()">
        @foreach ($this->materials as $material)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('documents.show', $material->document) }}" class="underline" wire:navigate>{{ $material->document->title }}</a></td>
                <td class="max-w-xl truncate px-3 py-2"><a href="{{ route('materials.show', $material) }}" class="underline" wire:navigate>{{ json_encode($material->data, JSON_UNESCAPED_UNICODE) }}</a></td>
                <td class="px-3 py-2">{{ $material->articles_count }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $material->created_at->format('Y-m-d H:i') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
