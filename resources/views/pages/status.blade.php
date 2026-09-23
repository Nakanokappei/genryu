{{-- A background job's status as a badge: a source's configuration (pending / ready / failed), a document's fetch (fetching / fetched / failed), a material's extraction (extracting / extracted / failed), an article's generation (generating / draft / failed, then published), a screening (screening / screened / failed), a quality check (checking / checked / failed), or a document the title filter excludes (excluded). --}}
@props(['status'])

<flux:badge size="sm" :color="match ($status) { 'ready', 'fetched', 'extracted', 'screened', 'checked', 'published' => 'green', 'draft' => 'blue', 'excluded' => 'amber', 'failed' => 'red', default => 'zinc' }">{{ __($status) }}</flux:badge>
