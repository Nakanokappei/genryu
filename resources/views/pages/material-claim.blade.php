{{-- One claim of a material: its statement, type and confidence, then what it rests on — the quotes with their lines for evidence, the claims for an inference. --}}
@props(['claim', 'id', 'claims', 'spans'])

@if ($claim === null)
    <flux:text size="sm" class="text-red-600">{{ $id }}: {{ __('missing claim') }}</flux:text>
@else
    <div class="rounded-lg border border-neutral-200 p-2 text-sm dark:border-neutral-700">
        <div class="flex flex-wrap items-baseline gap-2">
            <span class="font-mono text-xs text-neutral-400">{{ $claim['id'] }}</span>
            <span>{{ $claim['statement'] }}</span>
            <flux:badge size="sm" :color="$claim['type'] === 'primary_evidence' ? 'blue' : 'zinc'">{{ __($claim['type']) }}</flux:badge>
            <flux:badge size="sm" :color="match ($claim['confidence']) { 'high' => 'green', 'medium' => 'amber', 'low' => 'red', default => 'zinc' }">{{ __($claim['epistemic_status']) }} / {{ __($claim['confidence']) }}</flux:badge>
        </div>
        @if ($claim['type'] === 'primary_evidence')
            @foreach ($claim['basis'] as $spanId)
                @php $span = $spans[$spanId] ?? null; @endphp
                <blockquote class="mt-1 border-l-2 border-neutral-300 pl-2 text-neutral-600 dark:text-neutral-300">
                    @if ($span)「{{ $span['quote'] }}」<span class="text-xs text-neutral-400">（{{ $span['line_start'] === $span['line_end'] ? __('line :n', ['n' => $span['line_start']]) : __('lines :from–:to', ['from' => $span['line_start'], 'to' => $span['line_end']]) }}）</span>@else <span class="text-red-600">{{ $spanId }}: {{ __('missing span') }}</span>@endif
                </blockquote>
            @endforeach
        @elseif ($claim['basis'] !== [])
            <flux:text size="sm" class="text-neutral-500">{{ __('Rests on') }}: {{ implode(', ', $claim['basis']) }}</flux:text>
        @endif
        @if (($claim['limitations'] ?? []) !== [])
            <flux:text size="sm" class="text-neutral-500">{{ __('Limitations') }}: {{ implode(' / ', $claim['limitations']) }}</flux:text>
        @endif
    </div>
@endif
