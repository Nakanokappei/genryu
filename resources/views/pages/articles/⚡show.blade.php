<?php

use App\Models\Article;
use Livewire\Attributes\Title;
use Livewire\Component;

// 記事 (Article) detail: the text, its status, and the material it came from.
new #[Title('記事')] class extends Component {
    public Article $article;
}; ?>

<section class="w-full space-y-6">
    <x-pages::detail-header :back="route('articles.index')" :back-label="__('Articles')" :title="$article->title" />

    <x-pages::fields :fields="[
        __('Material') => $article->material?->document->title,
        __('Status') => __($article->status),
        __('Published at') => $article->published_at?->display() ?? __('Not published.'),
        __('Created') => $article->created_at->display(),
    ]" />

    <flux:heading size="lg">{{ __('Body') }}</flux:heading>
    <pre class="rounded-xl border border-neutral-200 p-4 text-sm whitespace-pre-wrap dark:border-neutral-700">{{ $article->body }}</pre>
</section>
