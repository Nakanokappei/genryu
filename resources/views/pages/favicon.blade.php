{{-- The source's favicon, once fetched. --}}
@props(['source'])

@if ($source?->favicon_path)
    <img src="{{ route('editorial.sources.favicon', $source) }}" alt="" class="inline-block size-4 rounded-sm align-[-3px]">
@endif
