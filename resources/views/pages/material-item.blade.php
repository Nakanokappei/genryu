{{-- One item of a material: its value, where it came from (the document with its quotes and lines, the model's general knowledge, or nowhere). --}}
@props(['label', 'item'])

@php $value = $item['value'] ?? null; $source = $item['source'] ?? 'none'; @endphp

<div class="space-y-1">
    <div class="flex items-center gap-2">
        <flux:subheading>{{ $label }}</flux:subheading>
        <flux:badge size="sm" :color="match ($source) { 'document' => 'blue', 'knowledge' => 'amber', default => 'zinc' }">{{ __($source) }}</flux:badge>
    </div>
    @if ($value === null)
        <flux:text size="sm" class="text-neutral-500">—</flux:text>
    @elseif (is_array($value))
        <ul class="list-disc ps-5 text-sm">@foreach ($value as $line)<li>{{ $line }}</li>@endforeach</ul>
    @else
        <flux:text size="sm">{{ $value }}</flux:text>
    @endif
    @foreach ($item['quotes'] ?? [] as $quote)
        <blockquote class="border-l-2 border-neutral-300 pl-2 text-sm text-neutral-600 dark:border-neutral-600 dark:text-neutral-300">
            「{{ $quote['quote'] }}」<span class="text-xs text-neutral-400">（{{ $quote['line_start'] === $quote['line_end'] ? __('line :n', ['n' => $quote['line_start']]) : __('lines :from–:to', ['from' => $quote['line_start'], 'to' => $quote['line_end']]) }}）</span>
        </blockquote>
    @endforeach
</div>
