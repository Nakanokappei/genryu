{{-- A background job's status as a badge: a source's configuration (pending / ready / failed), a document's fetch (fetching / fetched / failed) or a material's extraction (extracting / extracted / failed). --}}
@props(['status'])

<flux:badge size="sm" :color="match ($status) { 'ready', 'fetched', 'extracted' => 'green', 'failed' => 'red', default => 'zinc' }">{{ __($status) }}</flux:badge>
