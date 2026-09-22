{{-- One editorial lens on the change: the angle it gives, the before → change → after it rests on, the tension that makes it worth reading, and the statements behind it. --}}
@props(['lens', 'name'])

<div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
    <div class="flex flex-wrap items-center gap-2">
        <flux:heading>{{ __($name) }}</flux:heading>
        @if (($lens['strength'] ?? null) !== null)
            <flux:badge size="sm" :color="($lens['strength'] ?? '') === 'STRONG' ? 'green' : 'zinc'">{{ $lens['strength'] }}</flux:badge>
        @endif
    </div>

    @if (($lens['angle'] ?? null) !== null)
        <flux:text class="font-medium">{{ $lens['angle'] }}</flux:text>
    @endif

    <dl class="space-y-2 text-sm">
        @foreach (['before', 'change', 'after', 'tension'] as $part)
            @if (($lens[$part] ?? null) !== null)
                <div class="md:grid md:grid-cols-[6rem_1fr] md:gap-3">
                    <dt class="text-neutral-500">{{ __($part) }}</dt>
                    <dd>{{ $lens[$part] }}</dd>
                </div>
            @endif
        @endforeach
    </dl>

    @if (($lens['reason'] ?? null) !== null)
        <flux:text size="sm" class="text-neutral-500">{{ __('Why this lens was kept') }}: {{ $lens['reason'] }}</flux:text>
    @endif

    @if (($lens['claims'] ?? []) !== [])
        <div class="space-y-2">
            @foreach ($lens['claims'] as $claim)
                <x-pages::claim :claim="$claim" />
            @endforeach
        </div>
    @endif
</div>
