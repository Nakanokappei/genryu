{{-- A source as the screens name it: its favicon (once fetched) and its name, linked to its detail unless told otherwise. --}}
@props(['source', 'link' => true])

<span class="inline-flex items-center gap-1.5">
    @if ($source->favicon_path)
        <img src="{{ route('sources.favicon', $source) }}" alt="" class="size-4 rounded-sm">
    @endif
    @if ($link)
        <a href="{{ route('sources.show', $source) }}" class="underline" wire:navigate>{{ $source->name }}</a>
    @else
        {{ $source->name }}
    @endif
</span>
