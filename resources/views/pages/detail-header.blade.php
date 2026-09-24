{{-- A detail screen's back link and title, with the source's favicon. --}}
@props(['back', 'backLabel', 'title', 'source' => null])

<div class="space-y-2">
    <a href="{{ $back }}" class="text-sm text-neutral-500 underline" wire:navigate>← {{ $backLabel }}</a>
    @if ($source)
        <div class="text-sm"><a href="{{ route('editorial.sources.show', $source) }}" class="underline" wire:navigate>{{ $source->name }}</a></div>
    @endif
    <flux:heading size="xl"><x-pages::favicon :source="$source" /> {{ $title }}</flux:heading>
</div>
