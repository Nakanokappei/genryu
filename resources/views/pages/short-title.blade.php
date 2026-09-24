{{-- A title link cut short, the whole title as tooltip. --}}
@props(['title', 'href'])

@php $short = mb_strlen($title) > 31 ? mb_substr($title, 0, 31).'…' : $title; @endphp

@if ($short !== $title)
    <flux:tooltip :content="$title">
        <a href="{{ $href }}" class="underline" wire:navigate>{{ $short }}</a>
    </flux:tooltip>
@else
    <a href="{{ $href }}" class="underline" wire:navigate>{{ $title }}</a>
@endif
