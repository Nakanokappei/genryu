{{-- One article: source, headline, date, top image and body. --}}
@extends('media.layout')

@php($text = \App\Http\Controllers\MediaController::TEXT[$language])

@section('title', $article->headline.' — Technology Watch')

@section('content')
    <article class="mx-auto max-w-3xl">
        <a href="{{ route('media.language', $language) }}" class="text-xs text-[#6b665e] hover:underline">← {{ $text['back'] }}</a>
        <div class="mt-6">@include('media.source', ['article' => $article])</div>
        <h1 class="media-serif mt-3 text-3xl leading-tight font-extrabold sm:text-5xl">{{ $article->headline }}</h1>
        <div class="mt-4">@include('media.date', ['article' => $article])</div>

        @if (\App\Http\Controllers\MediaController::imagePathOf($article))
            <figure class="mt-8">
                @include('media.picture', ['article' => $article, 'class' => 'aspect-video w-full rounded-sm object-cover'])
                <figcaption class="mt-2 text-xs text-[#6b665e]">{{ $text['image'] }}</figcaption>
            </figure>
        @endif

        <div class="media-article media-serif mt-8 text-[1.07rem] leading-[1.95] [&_a]:underline [&_a]:underline-offset-2 [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:text-2xl [&_h2]:font-extrabold [&_h2]:leading-snug [&_p]:my-5">
            {!! $article->bodyHtml() !!}
        </div>
    </article>
@endsection
