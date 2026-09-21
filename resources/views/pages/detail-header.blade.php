{{-- Back link plus the title of a detail screen. --}}
@props(['back', 'backLabel', 'title'])

<div class="space-y-2">
    <a href="{{ $back }}" class="text-sm text-neutral-500 underline" wire:navigate>← {{ $backLabel }}</a>
    <flux:heading size="xl">{{ $title }}</flux:heading>
</div>
