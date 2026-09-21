{{-- A source's configuration status (pending / ready / failed) as a badge. --}}
@props(['status'])

<flux:badge size="sm" :color="match ($status) { 'ready' => 'green', 'failed' => 'red', default => 'zinc' }">{{ __($status) }}</flux:badge>
