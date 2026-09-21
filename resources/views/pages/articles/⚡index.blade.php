<?php

use App\Jobs\GenerateArticle;
use App\Models\Article;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 記事 (Articles): generated from each material in the background; nothing is written by hand here. Publishing comes later.
new #[Title('記事')] class extends Component {
    /** @return \Illuminate\Database\Eloquent\Collection<int, Article> */
    #[Computed]
    public function articles()
    {
        return Article::query()->with('material.document.updateEntry.source')->latest()->get();
    }

    // Stage 2.4: queue the generation for every extracted material whose article is missing or failed.
    public function generate(): void
    {
        $materials = Material::query()->where('status', 'extracted')->whereDoesntHave('articles', fn ($query) => $query->whereIn('status', ['generating', 'draft', 'published']))->get();
        $materials->each(fn (Material $material) => GenerateArticle::queueFor($material));
        unset($this->articles);

        Flux::toast(variant: 'success', text: __(':count articles queued.', ['count' => $materials->count()]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->articles->contains('status', 'generating')) wire:poll.5s @endif>
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="xl">{{ __('Articles') }}</flux:heading>
        <flux:button wire:click="generate" class="ms-auto" icon="pencil-square">{{ __('Generate articles') }}</flux:button>
    </div>

    <x-pages::table :columns="[__('Title'), __('Source'), __('Status'), __('Body'), __('Published at'), __('Created')]" :empty="$this->articles->isEmpty()">
        @foreach ($this->articles as $article)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayTitle() }}</a></td>
                <td class="px-3 py-2">{{ $article->material?->document->updateEntry->source->name }}</td>
                <td class="px-3 py-2"><x-pages::status :status="$article->status" /></td>
                <td class="max-w-xl truncate px-3 py-2 text-neutral-500">{{ $article->body ?? $article->status_message ?? '—' }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $article->published_at?->display() ?? __('Not published.') }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $article->created_at->display() }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
