<?php

use App\Jobs\GenerateArticle;
use App\Livewire\PagedList;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// 記事 (Articles): the article generation and translation layers of the editorial policy (the developer prompts and models of the writer and the translator), and the articles written from each material in the background, each with its translations; nothing is written by hand here. Publishing comes later.
new #[Title('記事')] class extends PagedList {
    /** The developer prompt of the writer, and the model it runs on. */
    public string $article = '';

    public string $articleModel = EditorialPolicy::DEFAULT_MODEL;

    /** The developer prompt of the translator, and the model it runs on. */
    public string $translation = '';

    public string $translationModel = EditorialPolicy::DEFAULT_MODEL;

    /** Which of the two prompts is open (UI: the tabs); both are saved together whichever is showing. */
    public string $layer = 'article';

    public function mount(): void
    {
        foreach (['article', 'translation'] as $layer) {
            $this->{$layer} = EditorialPolicy::bodyFor($layer);
            $this->{$layer.'Model'} = EditorialPolicy::modelFor($layer);
        }
    }

    public function savePolicy(): void
    {
        $models = ['required', 'in:'.implode(',', array_keys(EditorialPolicy::MODELS))];
        $this->validate(['articleModel' => $models, 'translationModel' => $models]);

        foreach (['article', 'translation'] as $layer) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $this->{$layer}, 'model' => $this->{$layer.'Model'}]);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Article> */
    #[Computed]
    public function articles()
    {
        // The list is the articles as written; their translations hang off them and are read on the article itself.
        return Article::query()->whereNull('translated_from_id')->with('material.document.source', 'translations')->latest()->latest('id')->paginate($this->rowsPerPage());
    }

    // Stage 2.4: queue the writing for every extracted material whose article is missing or failed; the translations follow each article on their own.
    public function generate(): void
    {
        $materials = Material::query()->where('status', 'extracted')->whereDoesntHave('articles', fn ($query) => $query->whereNull('translated_from_id')->whereIn('status', ['generating', 'draft', 'published']))->get();
        $materials->each(fn (Material $material) => GenerateArticle::queueFor($material));
        unset($this->articles);

        Flux::toast(variant: 'success', text: __(':count articles queued.', ['count' => $materials->count()]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->articles->contains(fn ($article) => $article->status === 'generating' || $article->translations->contains('status', 'generating'))) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Articles') }}</flux:heading>

    {{-- The two prompts sit with the articles because they are what make them: the writer's and the translator's. They are one setting read two ways, so they share a section and a save. --}}
    <form wire:submit="savePolicy" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="lg">{{ __('Editorial policy') }}</flux:heading>
            <flux:radio.group wire:model.live="layer" variant="segmented" size="sm" class="ms-auto">
                <flux:radio value="article" :label="__('Article generation')" />
                <flux:radio value="translation" :label="__('Translation')" />
            </flux:radio.group>
        </div>

        @if ($layer === 'translation')
            <flux:text>{{ __('The developer prompt and the model of the translator: the article is translated into the languages we publish in, never written again from the material, so the nuance of the primary source survives. The source and the material go along as context, because a translator without them mistranslates the terms.') }} {{ implode(' / ', array_map(fn ($code) => \App\Models\Article::LANGUAGE_NAMES[$code], \App\Models\Article::LANGUAGES)) }}</flux:text>
            <flux:textarea wire:model="translation" :label="__('Developer prompt')" rows="12" class="font-mono text-xs" />
            <flux:select wire:model="translationModel" :label="__('Model of the translation')" class="max-w-xl">
                @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                    <flux:select.option value="{{ $id }}">{{ $model['name'] }}（{{ $id }}）— {{ __($model['description']) }}</flux:select.option>
                @endforeach
            </flux:select>
        @else
            <flux:text>{{ __('The developer prompt and the model of the writer: an LLM turns a material into one article, written in the language of its primary source. Format, voice, length, shape and what may not be written are set here.') }}</flux:text>
            <flux:textarea wire:model="article" :label="__('Developer prompt')" rows="12" class="font-mono text-xs" />
            <flux:select wire:model="articleModel" :label="__('Model of the article generation')" class="max-w-xl">
                @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                    <flux:select.option value="{{ $id }}">{{ $model['name'] }}（{{ $id }}）— {{ __($model['description']) }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="generate" icon="pencil-square" wire:confirm="{{ __('Write an article from every material that has none? Each one is one call to the model, and its translations follow.') }}">{{ __('Generate articles') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Title'), __('Status'), __('Languages'), __('Published at'), __('Created')]" :empty="$this->articles->isEmpty()">
        @foreach ($this->articles as $article)
            <tr>
                <td class="px-3 py-2"><x-pages::favicon :source="$article->material?->document->source" /> <a href="{{ route('articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayTitle() }}</a></td>
                <td class="px-3 py-2"><x-pages::status :status="$article->status" /> <span class="text-neutral-500">{{ $article->body === null ? $article->status_message : '' }}</span></td>
                {{-- The original and every translation that is written, so a missing language shows as a missing name. --}}
                <td class="px-3 py-2 text-neutral-500">{{ implode(' / ', $article->translations->where('status', '!=', 'failed')->prepend($article)->map(fn ($written) => $written->languageName())->all()) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-neutral-500">{{ $article->published_at?->display() ?? __('Not published.') }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-neutral-500">{{ $article->created_at->display() }}</td>
            </tr>
        @endforeach
    </x-pages::table>

    <x-pages::pagination :paginator="$this->articles" />
</section>
