{{-- An article's publication time. --}}
@php($media = \App\Http\Controllers\MediaController::class)
@php($date = $media::dateOf($article))
<p class="flex flex-wrap items-center gap-x-2 text-xs text-[#6b665e]">
    <time datetime="{{ $date->toIso8601String() }}">{{ $date->locale($language)->isoFormat('LLL') }}</time>
</p>
