{{-- Back link, the source the record came from (with its favicon) when given, and the title of a detail screen. --}}
@props(['back', 'backLabel', 'title', 'source' => null])

<div class="space-y-2">
    <a href="{{ $back }}" class="text-sm text-neutral-500 underline" wire:navigate>← {{ $backLabel }}</a>
    @if ($source)
        <div class="text-sm"><x-pages::source-name :source="$source" /></div>
    @endif
    <flux:heading size="xl">{{ $title }}</flux:heading>
</div>
