{{-- A status value as a coloured badge, labelled through lang/ja.json. --}}
@props(['status'])

<flux:badge size="sm" :color="match ($status) { 'configured', 'fetched', 'extracted', 'screened', 'checked', 'made', 'published' => 'green', 'written', 'scheduled' => 'blue', 'imaging' => 'sky', 'excluded', 'left_out' => 'amber', 'failed' => 'red', default => 'zinc' }">{{ __($status) }}</flux:badge>
