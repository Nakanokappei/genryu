{{-- An article's publication time, marked when it is still to come. --}}
@php($media = \App\Http\Controllers\MediaController::class)
@php($date = $media::dateOf($article))
<p class="flex flex-wrap items-center gap-x-2 text-xs text-[#6b665e]">
    <time datetime="{{ $date->toIso8601String() }}">{{ $date->locale($language)->isoFormat('LLL') }}</time>
    @if ($date->isFuture())
        <span class="rounded-sm bg-[#8a2f1d] px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $media::TEXT[$language]['scheduled'] }}</span>
    @endif
</p>
