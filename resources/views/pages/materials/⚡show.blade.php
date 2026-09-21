<?php

use App\Models\Material;
use Livewire\Attributes\Title;
use Livewire\Component;

// 素材情報 (Material) detail: the JSON and the articles generated from it.
new #[Title('素材情報')] class extends Component {
    public Material $material;
}; ?>

<section class="w-full space-y-6">
    <x-pages::detail-header :back="route('materials.index')" :back-label="__('Materials')" :title="$material->document->title" />

    <x-pages::fields :fields="[
        __('Document') => $material->document->title,
        __('Created') => $material->created_at->display(),
    ]" />

    <flux:heading size="lg">{{ __('Data') }}</flux:heading>
    <pre class="max-h-96 overflow-auto rounded-xl border border-neutral-200 p-4 text-sm whitespace-pre-wrap dark:border-neutral-700">{{ json_encode($material->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>

    <flux:heading size="lg">{{ __('Articles') }}</flux:heading>
    <x-pages::table :columns="[__('Title'), __('Status'), __('Published at')]" :empty="$material->articles->isEmpty()">
        @foreach ($material->articles as $article)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('articles.show', $article) }}" class="underline" wire:navigate>{{ $article->title }}</a></td>
                <td class="px-3 py-2">{{ __($article->status) }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $article->published_at?->display() ?? __('Not published.') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
