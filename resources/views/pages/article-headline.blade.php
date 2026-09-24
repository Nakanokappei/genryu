{{-- An article's headline linked to its page, after its source's favicon. --}}
@props(['article'])

<x-pages::favicon :source="$article->material?->document->source" /> <a href="{{ route('editorial.articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayHeadline() }}</a>
