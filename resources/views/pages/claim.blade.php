{{-- One statement of a material, with where it comes from (the primary source, general knowledge, inference), how sure the model is, and what it rests on. --}}
@props(['claim'])

@php $type = $claim['type'] ?? ''; @endphp

<div class="space-y-1 border-l-2 border-neutral-200 ps-3 dark:border-neutral-700">
    <div class="flex flex-wrap items-center gap-2">
        <flux:badge size="sm" :color="match ($type) { 'primary_source' => 'blue', 'general_knowledge' => 'amber', 'inference' => 'purple', default => 'zinc' }">{{ __($type) }}</flux:badge>
        @if (($claim['confidence'] ?? null) !== null)
            <flux:text size="sm" class="text-neutral-400">{{ __('confidence') }} {{ __($claim['confidence']) }}</flux:text>
        @endif
    </div>
    <flux:text size="sm">{{ $claim['statement'] ?? '' }}</flux:text>
    @if (($claim['basis'] ?? null) !== null)
        <flux:text size="sm" class="text-neutral-500">{{ __('basis') }}: {{ $claim['basis'] }}</flux:text>
    @endif
</div>
