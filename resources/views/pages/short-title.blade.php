{{-- A document title as a link on a list row: cut at 31 characters, the whole of it as the tooltip. --}}
@props(['title', 'href'])

@php $short = mb_strlen($title) > 31 ? mb_substr($title, 0, 31).'…' : $title; @endphp

@if ($short !== $title)
    <flux:tooltip :content="$title">
        <a href="{{ $href }}" class="underline" wire:navigate>{{ $short }}</a>
    </flux:tooltip>
@else
    <a href="{{ $href }}" class="underline" wire:navigate>{{ $title }}</a>
@endif
