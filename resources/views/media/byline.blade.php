{{-- An article's source and publication time. --}}
@php($media = \App\Http\Controllers\MediaController::class)
<p class="flex flex-wrap items-center gap-x-2 text-xs text-[#6b665e]">
    <span class="font-semibold tracking-wide text-[#8a2f1d] uppercase">{{ $article->material?->document?->source?->name }}</span>
    <span aria-hidden="true">·</span>
    @php($date = $media::dateOf($article))
    <time datetime="{{ $date->toIso8601String() }}">{{ $date->locale($language)->isoFormat('LLL') }}</time>
    @if ($date->isFuture())
        <span class="rounded-sm bg-[#8a2f1d] px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $media::TEXT[$language]['scheduled'] }}</span>
    @endif
</p>
