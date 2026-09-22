{{-- The favicon of the source a record came from (once fetched), shown before the record's title. --}}
@props(['source'])

@if ($source?->favicon_path)
    <img src="{{ route('sources.favicon', $source) }}" alt="" class="inline-block size-4 rounded-sm align-[-3px]">
@endif
