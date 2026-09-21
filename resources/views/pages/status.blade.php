{{-- A background job's status as a badge: a source's configuration (pending / ready / failed), a document's fetch (fetching / fetched / failed), a material's extraction (extracting / extracted / failed) or an article's generation (generating / draft / failed, then published). --}}
@props(['status'])

<flux:badge size="sm" :color="match ($status) { 'ready', 'fetched', 'extracted', 'published' => 'green', 'draft' => 'blue', 'failed' => 'red', default => 'zinc' }">{{ __($status) }}</flux:badge>
