<?php

use App\Jobs\GenerateArticle;
use App\Livewire\PagedList;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\LanguageSetting;
use App\Models\Material;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// 記事 (Articles): the language settings (which sources get an article in each language), the headline, article generation and translation layers of the editorial policy (the developer prompts and models of the headline loop, the writer and the translator) in the order they are applied, with the additional prompt of each language shared by all three, and the articles written from each material in the background, each with its translations; nothing is written by hand here.
new #[Title('記事')] class extends PagedList {
    /** The developer prompt of the headline loop, and the model it runs on. */
    public string $headline = '';

    public string $headlineModel = EditorialPolicy::DEFAULT_MODEL;

    /** The developer prompt of the writer, and the model it runs on. */
    public string $article = '';

    public string $articleModel = EditorialPolicy::DEFAULT_MODEL;

    /** The developer prompt of the translator, and the model it runs on. */
    public string $translation = '';

    public string $translationModel = EditorialPolicy::DEFAULT_MODEL;

    /** Which of the three prompts is open (UI: the tabs, in the order they are applied); all are saved together whichever is showing. */
    public string $layer = 'headline';

    /** 言語設定: for each language, which sources get an article in it (all / own / none). @var array<string, string> */
    public array $coverages = [];

    /** 言語別の追加プロンプト: for each language, what every writer is given when it writes in it. @var array<string, string> */
    public array $languagePrompts = [];

    /** Which language's additional prompt is open (UI: the language tabs). */
    public string $promptLanguage = 'ja';

    public function mount(): void
    {
        foreach (['headline', 'article', 'translation'] as $layer) {
            $this->{$layer} = EditorialPolicy::bodyFor($layer);
            $this->{$layer.'Model'} = EditorialPolicy::modelFor($layer);
        }

        foreach (Article::LANGUAGES as $language) {
            $this->coverages[$language] = LanguageSetting::coverage($language);
            $this->languagePrompts[$language] = LanguageSetting::prompt($language);
        }
    }

    public function saveLanguages(): void
    {
        $this->validate(['coverages.*' => ['required', 'in:'.implode(',', LanguageSetting::COVERAGES)]]);
        $this->storeLanguageSettings();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** Both halves of a language's row, whichever form was saved: its coverage and its additional prompt. */
    private function storeLanguageSettings(): void
    {
        foreach (Article::LANGUAGES as $language) {
            LanguageSetting::query()->updateOrCreate(['language' => $language], ['coverage' => $this->coverages[$language] ?? LanguageSetting::coverage($language), 'prompt' => trim($this->languagePrompts[$language] ?? '')]);
        }
    }

    public function savePolicy(): void
    {
        $models = ['required', 'in:'.implode(',', array_keys(EditorialPolicy::MODELS))];
        $this->validate(['headlineModel' => $models, 'articleModel' => $models, 'translationModel' => $models]);

        foreach (['headline', 'article', 'translation'] as $layer) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $this->{$layer}, 'model' => $this->{$layer.'Model'}]);
        }

        // The additional prompts are one setting shared by the three layers, saved with them.
        $this->storeLanguageSettings();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Article> */
    #[Computed]
    public function articles()
    {
        // The list is the articles as written; their translations hang off them and are read on the article itself.
        return Article::query()->whereNull('translated_from_id')->with('material.document.source', 'translations')->latest()->latest('id')->paginate($this->rowsPerPage());
    }

    // Stage 2.4: queue the writing for every extracted material whose article is missing or failed, when some language wants its source (言語設定); the translations follow each article on their own.
    public function generate(): void
    {
        $materials = Material::query()->where('status', 'extracted')->whereDoesntHave('articles', fn ($query) => $query->whereNull('translated_from_id')->whereIn('status', ['generating', 'draft', 'published']))
            ->with('document')->get()->filter(fn (Material $material): bool => LanguageSetting::wantsArticle($material->document->language));
        $materials->each(fn (Material $material) => GenerateArticle::queueFor($material));
        unset($this->articles);

        Flux::toast(variant: 'success', text: __(':count articles queued.', ['count' => $materials->count()]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->articles->contains(fn ($article) => $article->status === 'generating' || $article->translations->contains('status', 'generating'))) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Articles') }}</flux:heading>

    {{-- 言語設定: which primary sources get an article in each language. An article is written in its source's language and translated from there. --}}
    <form wire:submit="saveLanguages" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Language settings') }}</flux:heading>
        <flux:text>{{ __('For each language, which primary sources get an article in it. An article is written in the language of its primary source and translated from there; when that language makes no articles but another takes every source, the original is still written, as the copy the translations are made from, and is not published.') }}</flux:text>
        <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
            @foreach (\App\Models\Article::LANGUAGES as $code)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" wire:key="coverage-{{ $code }}">
                    <span class="w-28 text-sm">{{ \App\Models\Article::LANGUAGE_NAMES[$code] }}</span>
                    <flux:select wire:model="coverages.{{ $code }}" size="sm" class="max-w-sm" :aria-label="\App\Models\Article::LANGUAGE_NAMES[$code]">
                        <flux:select.option value="all">{{ __('Articles from every primary source') }}</flux:select.option>
                        <flux:select.option value="own">{{ __('Only from primary sources in this language') }}</flux:select.option>
                        <flux:select.option value="none">{{ __('No articles') }}</flux:select.option>
                    </flux:select>
                </div>
            @endforeach
        </div>
        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>

    {{-- The three prompts sit with the articles because they are what make them: the headline loop's, the writer's and the translator's, in the order they are applied. They are one setting read three ways, so they share a section and a save. --}}
    <form wire:submit="savePolicy" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="lg">{{ __('Editorial policy') }}</flux:heading>
            <flux:radio.group wire:model.live="layer" variant="segmented" size="sm" class="ms-auto">
                <flux:radio value="headline" :label="__('Headline')" />
                <flux:radio value="article" :label="__('Article generation')" />
                <flux:radio value="translation" :label="__('Translation')" />
            </flux:radio.group>
        </div>

        {{-- What the layer says, then what the language says (shared by the three layers), then what is fixed: the order the messages are sent in. --}}
        @if ($layer === 'headline')
            <flux:text>{{ __('The developer prompt and the model of the headline loop, the first step of an article: a headline is written from the material in the language of the primary source and scored against the rubric below, and when it does not pass another is written with the review in hand and scored again, up to :attempts times. The best-scoring headline is kept, and the article is written under it. The rubric, its points and the pass mark are fixed in the code; this prompt says how to write a headline and how to judge it.', ['attempts' => \App\Jobs\RefineHeadline::ATTEMPTS]) }}</flux:text>
            <flux:textarea wire:model="headline" :label="__('Developer prompt (editable)')" rows="12" class="font-mono text-xs" />
        @elseif ($layer === 'translation')
            <flux:text>{{ __('The developer prompt and the model of the translator: the article is translated into the languages we publish in, never written again from the material, so the nuance of the primary source survives. The source and the material go along as context, because a translator without them mistranslates the terms.') }} {{ implode(' / ', array_map(fn ($code) => \App\Models\Article::LANGUAGE_NAMES[$code], \App\Models\LanguageSetting::translationTargets(null))) }}</flux:text>
            <flux:textarea wire:model="translation" :label="__('Developer prompt (editable)')" rows="12" class="font-mono text-xs" />
        @else
            <flux:text>{{ __('The developer prompt and the model of the writer: under the settled headline, an LLM turns a material into the body of one article, written in the language of its primary source. Format, voice, length, shape and what may not be written are set here.') }}</flux:text>
            <flux:textarea wire:model="article" :label="__('Developer prompt (editable)')" rows="12" class="font-mono text-xs" />
        @endif

        {{-- 言語別の追加プロンプト: one per language, given to the headline, the body and the translation alike whenever they are written in it. --}}
        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-3">
                <flux:label>{{ __('Additional prompt by language') }}</flux:label>
                {{-- Seven tabs do not fit a narrow screen: they scroll within their own row rather than widening the page. --}}
                <div class="ms-auto max-w-full overflow-x-auto">
                    <flux:radio.group wire:model.live="promptLanguage" variant="segmented" size="sm">
                        @foreach (\App\Models\Article::LANGUAGES as $code)
                            <flux:radio value="{{ $code }}" label="{{ \App\Models\Article::LANGUAGE_NAMES[$code] }}" />
                        @endforeach
                    </flux:radio.group>
                </div>
            </div>
            <flux:textarea wire:model="languagePrompts.{{ $promptLanguage }}" wire:key="language-prompt-{{ $promptLanguage }}" rows="4" class="font-mono text-xs" :placeholder="__('What belongs to the language rather than to the article, such as its style of writing.')" />
            <flux:text size="sm" class="text-neutral-500">{{ __('Shared by the headline, the article generation and the translation: given whenever they write in this language.') }}</flux:text>
        </div>

        @if ($layer === 'headline')
            <x-pages::fixed-prompts :instruction="\App\Actions\ProposeHeadline::INSTRUCTIONS.PHP_EOL.PHP_EOL.\App\Actions\ScoreHeadline::INSTRUCTIONS.PHP_EOL.PHP_EOL.\App\Actions\ScoreHeadline::rubric()" :input="[__('The material, as JSON'), __('The headline'), __('The headlines already tried and the review of the last one')]" />
            <flux:select wire:model="headlineModel" :label="__('Model of the headline')" class="max-w-xl">
                @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                    <flux:select.option value="{{ $id }}">{{ $model['name'] }}（{{ $id }}）— {{ __($model['description']) }}</flux:select.option>
                @endforeach
            </flux:select>
        @elseif ($layer === 'translation')
            <x-pages::fixed-prompts :instruction="str_replace('%s', __('the target language'), \App\Actions\ProposeTranslation::INSTRUCTIONS)" :input="[__('The article as written'), __('The title of the primary source'), __('The URL of the primary source'), __('The material, as JSON')]" />
            <flux:select wire:model="translationModel" :label="__('Model of the translation')" class="max-w-xl">
                @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                    <flux:select.option value="{{ $id }}">{{ $model['name'] }}（{{ $id }}）— {{ __($model['description']) }}</flux:select.option>
                @endforeach
            </flux:select>
        @else
            <x-pages::fixed-prompts :instruction="\App\Actions\ProposeArticle::INSTRUCTIONS" :input="[__('The headline'), __('The title of the primary source'), __('The URL of the primary source'), __('The material, as JSON')]" />
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
                <td class="px-3 py-2"><x-pages::favicon :source="$article->material?->document->source" /> <a href="{{ route('editorial.articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayTitle() }}</a></td>
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
