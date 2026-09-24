<?php

use App\Jobs\GenerateArticle;
use App\Jobs\TranslateArticle;
use App\Models\Article;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 記事 (Article) detail: how the writing went, the article in each language it exists in, and the material it came from. The article as written and its translations are one page: the languages are tabs over the same piece.
new #[Title('記事')] class extends Component {
    public Article $article;

    /** The language being read (UI: the tabs); the original's until another is chosen. */
    public string $language = '';

    /** Which way the article is read (UI: 記事 / Markdown); the same text either way. */
    public string $view = 'article';

    public function mount(): void
    {
        $this->language = (string) $this->original()->language;
    }

    /** The article as written, whichever of its languages was opened. */
    public function original(): Article
    {
        return $this->article->original() ?? $this->article;
    }

    /**
     * The article in every language it exists in, the original first.
     *
     * @return \Illuminate\Support\Collection<string, Article>
     */
    #[Computed]
    public function versions()
    {
        $original = $this->original();

        return $original->translations()->get()->prepend($original)->keyBy(fn (Article $article): string => (string) $article->language);
    }

    /** The one being read. */
    #[Computed]
    public function reading(): Article
    {
        return $this->versions[$this->language] ?? $this->original();
    }

    /**
     * The one the screen itself is headed and listed by: the version in
     * the language of the interface, else the article as written. The
     * tabs change what is read, not what the page around it is called.
     */
    #[Computed]
    public function inUiLanguage(): Article
    {
        return $this->versions[app()->getLocale()] ?? $this->original();
    }

    // Queue the writing again (after a failure, or after the editorial policy changed); the translations follow it.
    public function generate(): void
    {
        GenerateArticle::queueFor($this->original()->material);
        unset($this->versions, $this->reading, $this->inUiLanguage);

        Flux::toast(variant: 'success', text: __('Article queued.'));
    }

    // Queue the translation being read again, leaving the article as written alone.
    public function translate(): void
    {
        TranslateArticle::queueFor($this->original(), $this->language);
        unset($this->versions, $this->reading, $this->inUiLanguage);

        Flux::toast(variant: 'success', text: __('Article queued.'));
    }

    // Polled while a background job runs so the screen follows it.
    public function refreshStatus(): void
    {
        $this->article->refresh();
        unset($this->versions, $this->reading, $this->inUiLanguage);
    }
}; ?>

<section class="w-full space-y-6" @if ($this->versions->contains('status', 'generating')) wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('editorial.articles.index')" :back-label="__('Articles')" :source="$this->original()->material?->document->source" :title="$this->inUiLanguage->displayHeadline()" />

    <x-pages::fields :fields="[
        __('Document') => $this->original()->material?->document->title,
        __('URL') => $this->original()->material?->document->url,
        __('Published at') => $this->inUiLanguage->published_at?->display() ?? __('Not published.'),
        __('Created') => $this->original()->created_at->display(),
    ]" />

    {{-- The languages are tabs over one piece: the article as written, then each translation of it. --}}
    <flux:radio.group wire:model.live="language" variant="segmented" size="sm">
        @foreach (\App\Enums\Language::names() as $code => $name)
            @if ($this->versions->has($code))
                <flux:radio value="{{ $code }}" label="{{ $name }}{{ $this->versions[$code]->isOriginal() ? '（'.__('Original article').'）' : '' }}" />
            @endif
        @endforeach
    </flux:radio.group>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <x-pages::status :status="$this->reading->status" />
        <flux:text class="flex-1">{{ $this->reading->status_message ?? '—' }}</flux:text>
        @if ($this->original()->material)
            <a href="{{ route('editorial.materials.show', $this->original()->material) }}" class="text-sm underline" wire:navigate>{{ __('Material') }}</a>
            @if ($this->reading->isOriginal())
                <flux:button wire:click="generate" size="sm" icon="arrow-path">{{ __('Generate again') }}</flux:button>
            @else
                <flux:button wire:click="translate" size="sm" icon="arrow-path">{{ __('Translate again') }}</flux:button>
            @endif
        @endif
    </div>

    @if ($this->reading->model !== null)
        <flux:text size="sm" class="text-neutral-500">
            {{ $this->reading->model }} / {{ __('Prompt version') }} v{{ $this->reading->prompt?->version ?? '—' }} /
            {{ __('Tokens') }}: {{ __('input') }} {{ number_format((int) $this->reading->input_tokens) }}（{{ __('cached') }} {{ number_format((int) $this->reading->cached_tokens) }}）, {{ __('output') }} {{ number_format((int) $this->reading->output_tokens) }} /
            {{ number_format((int) $this->reading->latency_ms) }} ms / {{ $this->reading->estimated_total_cost !== null ? '$'.number_format($this->reading->estimated_total_cost, 5) : __('cost unknown') }}
        </flux:text>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="lg">{{ __('Article') }}</flux:heading>
        <flux:radio.group wire:model.live="view" variant="segmented" size="sm" class="ms-auto">
            <flux:radio value="article" :label="__('Article')" />
            <flux:radio value="markdown" :label="__('Markdown')" />
        </flux:radio.group>
    </div>

    @if ($this->reading->body !== null)
        {{-- The title belongs with the text it heads: switching language shows that language's title above its body. The two tabs are the same text, read two ways. --}}
        <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            @if ($view === 'markdown')
                <pre class="overflow-auto text-sm whitespace-pre-wrap"># {{ $this->reading->headline }}

{{ $this->reading->body }}</pre>
            @else
                <flux:heading size="lg">{{ $this->reading->headline }}</flux:heading>
                <div class="text-sm leading-relaxed [&_[data-lead]]:mb-4 [&_[data-lead]]:border-b [&_[data-lead]]:border-neutral-200 [&_[data-lead]]:pb-3 [&_[data-lead]]:text-neutral-600 dark:[&_[data-lead]]:border-neutral-700 dark:[&_[data-lead]]:text-neutral-300 [&_a]:underline [&_h1]:my-3 [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:my-3 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:my-2 [&_h3]:font-semibold [&_li]:my-1 [&_ol]:list-decimal [&_ol]:ps-5 [&_p]:my-2 [&_ul]:list-disc [&_ul]:ps-5">{!! $this->reading->bodyHtml() !!}</div>
            @endif
        </div>
    @else
        <flux:text>{{ __('Not generated yet.') }}</flux:text>
    @endif
</section>
