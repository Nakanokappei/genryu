{{-- The front page: the newest article large, the rest as cards. --}}
@extends('media.layout')

@php($text = \App\Http\Controllers\MediaController::TEXT[$language])
@php($media = \App\Http\Controllers\MediaController::class)

@section('content')
    @if ($articles->isEmpty())
        <p class="py-24 text-center text-[#6b665e]">{{ $text['empty'] }}</p>
    @else
        @php($top = $articles->first())
        {{-- The top story. --}}
        <article class="grid gap-6 border-b border-[#d9d4ca] pb-10 md:grid-cols-5">
            <a href="{{ route('media.article', [$language, $top]) }}" class="md:col-span-3">
                @include('media.picture', ['article' => $top, 'class' => 'aspect-video w-full rounded-sm object-cover'])
            </a>
            <div class="flex flex-col justify-center md:col-span-2">
                @include('media.byline', ['article' => $top])
                <h2 class="media-serif mt-2 text-3xl leading-tight font-extrabold sm:text-4xl"><a href="{{ route('media.article', [$language, $top]) }}" class="hover:underline">{{ $top->headline }}</a></h2>
                <p class="mt-4 leading-relaxed text-[#3d3a35]">{{ Str::limit($media::lead($top), 220) }}</p>
            </div>
        </article>

        @if ($articles->count() > 1)
            <h2 class="mt-10 mb-5 text-xs font-semibold tracking-[0.2em] text-[#6b665e] uppercase">{{ $text['latest'] }}</h2>
            <div class="grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($articles->slice(1) as $article)
                    <article>
                        <a href="{{ route('media.article', [$language, $article]) }}">
                            @include('media.picture', ['article' => $article, 'class' => 'aspect-video w-full rounded-sm object-cover'])
                        </a>
                        <div class="mt-3">@include('media.byline', ['article' => $article])</div>
                        <h3 class="media-serif mt-1 text-xl leading-snug font-bold"><a href="{{ route('media.article', [$language, $article]) }}" class="hover:underline">{{ $article->headline }}</a></h3>
                        <p class="mt-2 text-sm leading-relaxed text-[#3d3a35]">{{ Str::limit($media::lead($article), 120) }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    @endif
@endsection
