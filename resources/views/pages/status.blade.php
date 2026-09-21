{{-- A background job's status as a badge: a source's configuration (pending / ready / failed) or a document's fetch (fetching / fetched / failed). --}}
@props(['status'])

<flux:badge size="sm" :color="match ($status) { 'ready', 'fetched' => 'green', 'failed' => 'red', default => 'zinc' }">{{ __($status) }}</flux:badge>
