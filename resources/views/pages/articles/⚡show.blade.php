<?php

use App\Jobs\GenerateArticle;
use App\Models\Article;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 記事 (Article) detail: how the generation went, the text, and the material it came from.
new #[Title('記事')] class extends Component {
    public Article $article;

    // Queue the generation again (after a failure, or after the editorial policy changed).
    public function generate(): void
    {
        GenerateArticle::queueFor($this->article->material);
        $this->article->refresh();

        Flux::toast(variant: 'success', text: __('Article queued.'));
    }

    // Polled while generating so the screen follows the background job.
    public function refreshStatus(): void
    {
        $this->article->refresh();
    }
}; ?>

<section class="w-full space-y-6" @if ($article->status === 'generating') wire:poll.5s="refreshStatus" @endif>
    <x-pages::detail-header :back="route('articles.index')" :back-label="__('Articles')" :source="$article->material?->updateEntry->source" :title="$article->displayTitle()" />

    <x-pages::fields :fields="[
        __('Update') => $article->material?->updateEntry->title,
        __('URL') => $article->material?->updateEntry->url,
        __('Published at') => $article->published_at?->display() ?? __('Not published.'),
        __('Created') => $article->created_at->display(),
    ]" />

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <x-pages::status :status="$article->status" />
        <flux:text class="flex-1">{{ $article->status_message ?? '—' }}</flux:text>
        @if ($article->material)
            <a href="{{ route('materials.show', $article->material) }}" class="text-sm underline" wire:navigate>{{ __('Material') }}</a>
            <flux:button wire:click="generate" size="sm" icon="arrow-path">{{ __('Generate again') }}</flux:button>
        @endif
    </div>

    <flux:heading size="lg">{{ __('Body') }}</flux:heading>
    @if ($article->body !== null)
        {{-- The body is Markdown; rendered here with any HTML in it stripped. --}}
        <div class="rounded-xl border border-neutral-200 p-4 text-sm leading-relaxed [&_a]:underline [&_h1]:my-3 [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:my-3 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:my-2 [&_h3]:font-semibold [&_li]:my-1 [&_ol]:list-decimal [&_ol]:ps-5 [&_p]:my-2 [&_ul]:list-disc [&_ul]:ps-5 dark:border-neutral-700">{!! Str::markdown($article->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        <details class="text-sm">
            <summary class="cursor-pointer text-neutral-500">{{ __('Markdown') }}</summary>
            <pre class="mt-2 rounded-xl border border-neutral-200 p-4 whitespace-pre-wrap dark:border-neutral-700">{{ $article->body }}</pre>
        </details>
    @else
        <flux:text>{{ __('Not generated yet.') }}</flux:text>
    @endif
</section>
