<?php

use App\Enums\Language;
use App\Jobs\GenerateArticle;
use App\Livewire\PagedList;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\LanguageSetting;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// 記事 (Articles): 言語設定, the headline / article / translation layers, and the list of originals.
new #[Title('記事')] class extends PagedList {
    /** The headline layer's prompt and model. */
    public string $headline = '';

    public string $headlineModel = EditorialPolicy::DEFAULT_MODEL;

    /** The article generation layer's prompt and model. */
    public string $article = '';

    public string $articleModel = EditorialPolicy::DEFAULT_MODEL;

    /** The translation layer's prompt and model. */
    public string $translation = '';

    public string $translationModel = EditorialPolicy::DEFAULT_MODEL;

    /** The open layer tab. */
    public string $layer = 'headline';

    /** UI: 言語設定 — all / own / none per language. @var array<string, string> */
    public array $coverages = [];

    /** UI: 言語別の追加プロンプト. @var array<string, string> */
    public array $languagePrompts = [];

    /** The open language tab of the additional prompt. */
    public string $promptLanguage = 'ja';

    // Load the layers and language settings.
    public function mount(): void
    {
        foreach (['headline', 'article', 'translation'] as $layer) {
            $this->{$layer} = EditorialPolicy::bodyFor($layer);
            $this->{$layer.'Model'} = EditorialPolicy::modelFor($layer);
        }

        foreach (Language::codes() as $language) {
            $this->coverages[$language] = LanguageSetting::coverage($language);
            $this->languagePrompts[$language] = LanguageSetting::additionalPrompt($language);
        }
    }

    // Save 言語設定.
    public function saveLanguages(): void
    {
        $this->validate(['coverages.*' => ['required', 'in:'.implode(',', LanguageSetting::COVERAGES)]]);
        $this->storeLanguageSettings();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Store each language's coverage and additional prompt.
    private function storeLanguageSettings(): void
    {
        foreach (Language::codes() as $language) {
            LanguageSetting::query()->updateOrCreate(['language' => $language], ['coverage' => $this->coverages[$language] ?? LanguageSetting::coverage($language), 'additional_prompt' => trim($this->languagePrompts[$language] ?? '')]);
        }
    }

    // Save the three layers and the additional prompts.
    public function savePolicy(): void
    {
        $models = EditorialPolicy::modelRule();
        $this->validate(['headlineModel' => $models, 'articleModel' => $models, 'translationModel' => $models]);

        foreach (['headline', 'article', 'translation'] as $layer) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $this->{$layer}, 'model' => $this->{$layer.'Model'}]);
        }

        $this->storeLanguageSettings();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Article> the originals, with their translations */
    #[Computed]
    public function articles()
    {
        return Article::query()->originals()->with('material.document.source', 'translations')->latest()->latest('id')->paginate($this->rowsPerPage());
    }

    // Queue an article for every extracted material without one that some language wants.
    public function generate(): void
    {
        $materials = Material::query()->where('status', 'extracted')->whereDoesntHave('articles', fn ($query) => $query->originals()->whereIn('status', ['generating', 'written']))
            ->with('document')->get()->filter(fn (Material $material): bool => LanguageSetting::wantsArticle($material->document->language));
        $materials->each(fn (Material $material) => GenerateArticle::queueFor($material));
        unset($this->articles);

        Flux::toast(variant: 'success', text: __(':count articles queued.', ['count' => $materials->count()]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->articles->contains(fn ($article) => $article->status === 'generating' || $article->translations->contains('status', 'generating'))) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Articles') }}</flux:heading>

    {{-- 言語設定 --}}
    <form wire:submit="saveLanguages" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Language settings') }}</flux:heading>
        <flux:text>{{ __('For each language, which primary sources get an article in it. An article is written in the language of its primary source and translated from there; when that language makes no articles but another takes every source, the original is still written, as the copy the translations are made from, and is not published.') }}</flux:text>
        <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
            @foreach (\App\Enums\Language::codes() as $code)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" wire:key="coverage-{{ $code }}">
                    <span class="w-28 text-sm">{{ \App\Enums\Language::nameOf($code) }}</span>
                    <flux:select wire:model="coverages.{{ $code }}" size="sm" class="max-w-sm" :aria-label="\App\Enums\Language::nameOf($code)">
                        <flux:select.option value="all">{{ __('Articles from every primary source') }}</flux:select.option>
                        <flux:select.option value="own">{{ __('Only from primary sources in this language') }}</flux:select.option>
                        <flux:select.option value="none">{{ __('No articles') }}</flux:select.option>
                    </flux:select>
                </div>
            @endforeach
        </div>
        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>

    {{-- The three layers, saved together. --}}
    <form wire:submit="savePolicy" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="lg">{{ __('Editorial policy') }}</flux:heading>
            <flux:radio.group wire:model.live="layer" variant="segmented" size="sm" class="ms-auto">
                <flux:radio value="headline" :label="__('Headline')" />
                <flux:radio value="article" :label="__('Article generation')" />
                <flux:radio value="translation" :label="__('Translation')" />
            </flux:radio.group>
        </div>

        {{-- In the order sent: the layer, the language, the fixed instruction. --}}
        @if ($layer === 'headline')
            <flux:text>{{ __('The developer prompt and the model of the headline loop, the first step of an article: a headline is written from the material in the language of the primary source and scored against the rubric below, and when it does not pass another is written with the review in hand and scored again, up to :attempts times. The best-scoring headline is kept, and the article is written under it. The rubric, its points and the pass mark are fixed in the code; this prompt says how to write a headline and how to judge it.', ['attempts' => \App\Jobs\RefineHeadline::ATTEMPTS]) }}</flux:text>
            <flux:textarea wire:model="headline" :label="__('Developer prompt (editable)')" rows="12" class="font-mono text-xs" />
        @elseif ($layer === 'translation')
            <flux:text>{{ __('The developer prompt and the model of the translator: the article is translated into the languages we publish in, never written again from the material, so the nuance of the primary source survives. The source and the material go along as context, because a translator without them mistranslates the terms.') }} {{ implode(' / ', array_map(fn ($code) => \App\Enums\Language::nameOf($code), \App\Models\LanguageSetting::translationTargets(null))) }}</flux:text>
            <flux:textarea wire:model="translation" :label="__('Developer prompt (editable)')" rows="12" class="font-mono text-xs" />
        @else
            <flux:text>{{ __('The developer prompt and the model of the writer: under the settled headline, an LLM turns a material into the body of one article, written in the language of its primary source. Format, voice, length, shape and what may not be written are set here.') }}</flux:text>
            <flux:textarea wire:model="article" :label="__('Developer prompt (editable)')" rows="12" class="font-mono text-xs" />
        @endif

        {{-- 言語別の追加プロンプト --}}
        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-3">
                <flux:label>{{ __('Additional prompt by language') }}</flux:label>
                {{-- The tabs scroll on a narrow screen. --}}
                <div class="ms-auto max-w-full overflow-x-auto">
                    <flux:radio.group wire:model.live="promptLanguage" variant="segmented" size="sm">
                        @foreach (\App\Enums\Language::codes() as $code)
                            <flux:radio value="{{ $code }}" label="{{ \App\Enums\Language::nameOf($code) }}" />
                        @endforeach
                    </flux:radio.group>
                </div>
            </div>
            <flux:textarea wire:model="languagePrompts.{{ $promptLanguage }}" wire:key="language-prompt-{{ $promptLanguage }}" rows="4" class="font-mono text-xs" :placeholder="__('What belongs to the language rather than to the article, such as its style of writing.')" />
            <flux:text size="sm" class="text-neutral-500">{{ __('Shared by the headline, the article generation and the translation: given whenever they write in this language.') }}</flux:text>
        </div>

        @if ($layer === 'headline')
            <x-pages::fixed-prompts :instruction="\App\Actions\ProposeHeadline::INSTRUCTIONS.PHP_EOL.PHP_EOL.\App\Actions\ScoreHeadline::INSTRUCTIONS.PHP_EOL.PHP_EOL.\App\Actions\ScoreHeadline::rubric()" :input="[__('The material, as JSON'), __('The headline'), __('The headlines already tried and the review of the last one')]" />
            <x-pages::model-select wire:model="headlineModel" :label="__('Model of the headline')" class="max-w-xl" />
        @elseif ($layer === 'translation')
            <x-pages::fixed-prompts :instruction="str_replace('%s', __('the target language'), \App\Actions\ProposeTranslation::INSTRUCTIONS)" :input="[__('The article as written'), __('The title of the primary source'), __('The URL of the primary source'), __('The material, as JSON')]" />
            <x-pages::model-select wire:model="translationModel" :label="__('Model of the translation')" class="max-w-xl" />
        @else
            <x-pages::fixed-prompts :instruction="\App\Actions\ProposeArticle::INSTRUCTIONS" :input="[__('The headline'), __('The title of the primary source'), __('The URL of the primary source'), __('The material, as JSON')]" />
            <x-pages::model-select wire:model="articleModel" :label="__('Model of the article generation')" class="max-w-xl" />
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="generate" icon="pencil-square" wire:confirm="{{ __('Write an article from every material that has none? Each one is one call to the model, and its translations follow.') }}">{{ __('Generate articles') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Headline'), __('Status'), __('Languages'), __('Published at'), __('Created')]" :empty="$this->articles->isEmpty()">
        @foreach ($this->articles as $article)
            <tr>
                <td class="px-3 py-2"><x-pages::article-headline :article="$article" /></td>
                <td class="px-3 py-2"><x-pages::status :status="$article->status" /> <span class="text-neutral-500">{{ $article->body === null ? $article->status_message : '' }}</span></td>
                {{-- The languages written so far. --}}
                <td class="px-3 py-2 text-neutral-500">{{ implode(' / ', $article->translations->where('status', '!=', 'failed')->prepend($article)->map(fn ($written) => $written->languageName())->all()) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-neutral-500">{{ $article->published_at?->display() ?? __('Not published.') }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-neutral-500">{{ $article->created_at->display() }}</td>
            </tr>
        @endforeach
    </x-pages::table>

    <x-pages::pagination :paginator="$this->articles" />
</section>
