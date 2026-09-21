<?php

use App\Models\Article;
use App\Models\Material;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 記事 (Articles): drafts written from materials, by hand for now; publishing comes later.
new #[Title('記事')] class extends Component {
    #[Validate('nullable|exists:materials,id')]
    public string $material_id = '';

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string')]
    public string $body = '';

    /** @return \Illuminate\Database\Eloquent\Collection<int, Article> */
    #[Computed]
    public function articles()
    {
        return Article::query()->with('material.document')->latest()->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Material> */
    #[Computed]
    public function materials()
    {
        return Material::query()->with('document')->latest()->get();
    }

    public function add(): void
    {
        $validated = $this->validate();
        Article::create([...$validated, 'material_id' => $this->material_id !== '' ? $this->material_id : null]);
        $this->reset('title', 'body');
        unset($this->articles);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Articles') }}</flux:heading>

    <form wire:submit="add" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-4 dark:border-neutral-700">
        <flux:select wire:model="material_id" :label="__('Material')" :placeholder="__('Select')">
            @foreach ($this->materials as $material)
                <flux:select.option value="{{ $material->id }}">#{{ $material->id }} {{ $material->document->title }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="md:col-span-3">
            <flux:input wire:model="title" :label="__('Title')" />
        </div>
        <div class="md:col-span-4">
            <flux:textarea wire:model="body" :label="__('Body')" rows="8" />
        </div>
        <div class="flex items-end">
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Title'), __('Material'), __('Status'), __('Published at')]" :empty="$this->articles->isEmpty()">
        @foreach ($this->articles as $article)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('articles.show', $article) }}" class="underline" wire:navigate>{{ $article->title }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $article->material?->document->title }}</td>
                <td class="px-3 py-2">{{ __($article->status) }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $article->published_at?->format('Y-m-d H:i') ?? __('Not published.') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
