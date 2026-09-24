{{-- Where an article comes from and when it is out (or is to be). --}}
@php($media = \App\Http\Controllers\MediaController::class)
<p class="flex flex-wrap items-center gap-x-2 text-xs text-[#6b665e]">
    <span class="font-semibold tracking-wide text-[#8a2f1d] uppercase">{{ $article->material?->document?->source?->name }}</span>
    <span aria-hidden="true">·</span>
    <time datetime="{{ $media::dateOf($article)->toIso8601String() }}">{{ $media::dateOf($article)->locale($language)->isoFormat('LLL') }}</time>
    @if ($media::isUpcoming($article))
        <span class="rounded-sm bg-[#8a2f1d] px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ \App\Http\Controllers\MediaController::TEXT[$language]['scheduled'] }}</span>
    @endif
</p>
