<?php

use App\Actions\FetchUpdates;
use App\Jobs\ConfigureSource;
use App\Jobs\FetchDocument;
use App\Models\Source;
use App\Models\UpdateEntry;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 情報源 (Source) detail: edit or delete the site, fetch its update list, and see what was found.
new #[Title('情報源')] class extends Component {
    public Source $source;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    public string $notes = '';

    /** @var array<string, string> HTML list settings, all CSS selectors except max_pages */
    public array $list = ['item' => '', 'title' => '', 'date' => '', 'next' => '', 'max_pages' => '3'];

    /** @var array<string, string> JSON list settings: the file's URL, the path to the items, the keys inside an item, max_items */
    public array $json = ['url' => '', 'items' => '', 'title' => 'title', 'link' => 'url', 'date' => '', 'max_items' => '50'];

    /** @var array<string, string> Document settings: CSS selectors of the body, its date, what to drop inside it, and fixed text to move after it */
    public array $documentSettings = ['content' => '', 'date' => '', 'remove' => '', 'fixed_text' => ''];

    public function mount(): void
    {
        $this->name = $this->source->name;
        $this->url = $this->source->url;
        $this->notes = $this->source->notes ?? '';
        $this->readAsHtml = (bool) $this->source->read_as_html;

        foreach ($this->source->list_config ?? [] as $key => $value) {
            if (array_key_exists($key, $this->list)) {
                $this->list[$key] = (string) $value;
            }
        }

        foreach ($this->source->json_config ?? [] as $key => $value) {
            if (array_key_exists($key, $this->json)) {
                $this->json[$key] = (string) $value;
            }
        }

        foreach ($this->source->document_config ?? [] as $key => $value) {
            if (array_key_exists($key, $this->documentSettings)) {
                $this->documentSettings[$key] = (string) $value;
            }
        }
    }

    // The JSON list settings are saved on their own; an empty URL means "not read from JSON".
    public function saveJson(): void
    {
        $validated = $this->validate([
            'json.url' => ['nullable', 'url', 'max:2048'],
            'json.items' => ['nullable', 'string', 'max:255'],
            'json.title' => ['required', 'string', 'max:255'],
            'json.link' => ['required', 'string', 'max:255'],
            'json.date' => ['nullable', 'string', 'max:255'],
            'json.max_items' => ['required', 'integer', 'min:1', 'max:1000'],
        ])['json'];

        $this->source->update(['json_config' => ($validated['url'] ?? '') !== ''
            ? [...$validated, 'items' => (string) ($validated['items'] ?? ''), 'date' => (string) ($validated['date'] ?? ''), 'max_items' => (int) $validated['max_items']]
            : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // The document settings are saved on their own; an empty content selector means "let the agent propose at the next fetch".
    public function saveDocumentSettings(): void
    {
        $validated = $this->validate([
            'documentSettings.content' => ['nullable', 'string', 'max:255'],
            'documentSettings.date' => ['nullable', 'string', 'max:255'],
            'documentSettings.remove' => ['nullable', 'string', 'max:1000'],
            'documentSettings.fixed_text' => ['nullable', 'string', 'max:1000'],
        ])['documentSettings'];

        $this->source->update(['document_config' => ($validated['content'] ?? '') !== ''
            ? array_map(fn (?string $value): string => (string) $value, $validated)
            : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Stage 2.2: queue the fetch for every update entry whose document is missing or failed, leaving the excluded ones alone.
    public function fetchDocuments(): void
    {
        $entries = $this->source->updateEntries()->whereNull('excluded_by')->whereDoesntHave('document', fn ($query) => $query->whereIn('status', ['fetching', 'fetched']))->get();
        $entries->each(fn (UpdateEntry $entry) => FetchDocument::queueFor($entry));

        Flux::toast(variant: 'success', text: __(':count documents queued.', ['count' => $entries->count()]));
    }

    // The HTML list settings are saved separately from the name / URL form; an empty item means "read a feed".
    public function saveList(): void
    {
        $validated = $this->validate([
            'list.item' => ['nullable', 'string', 'max:255'],
            'list.title' => ['nullable', 'string', 'max:255'],
            'list.date' => ['nullable', 'string', 'max:255'],
            'list.next' => ['nullable', 'string', 'max:255'],
            'list.max_pages' => ['required', 'integer', 'min:1', 'max:100'],
        ])['list'];

        $this->source->update(['list_config' => $validated['item'] !== '' && $validated['item'] !== null
            ? [...$validated, 'max_pages' => (int) $validated['max_pages']]
            : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function save(): void
    {
        $validated = $this->validate();
        $this->source->update([...$validated, 'notes' => $this->notes !== '' ? $this->notes : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Deleting a source takes its updates, documents and materials with it (cascade).
    public function delete(): void
    {
        $this->source->delete();

        $this->redirectRoute('sources.index', navigate: true);
    }

    public bool $readAsHtml = false;

    // The operator's choice to skip feeds is saved as soon as it is toggled.
    public function updatedReadAsHtml(bool $value): void
    {
        $this->source->update(['read_as_html' => $value]);
    }

    // Queue the background configuration again (after a failure, or after the site changed).
    public function configure(): void
    {
        $this->source->update(['status' => 'pending', 'status_message' => null]);
        ConfigureSource::dispatch($this->source);

        Flux::toast(variant: 'success', text: __('Configuration queued.'));
    }

    // Polled while pending so the screen follows the background job; the settings form is refilled once it is done.
    public function refreshStatus(): void
    {
        $this->source->refresh();

        if ($this->source->status !== 'pending') {
            $this->mount();
        }
    }

    // Stage 2.1: read the feed (found deterministically) into the update list.
    public function fetchUpdates(FetchUpdates $fetch): void
    {
        try {
            $result = $fetch($this->source);
        } catch (\Throwable $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage(), duration: 8000);

            return;
        }

        $this->source->refresh();
        Flux::toast(variant: 'success', duration: 8000, text: $result['feed_url'] !== null
            ? __(':added added, :existing already listed (:feed)', ['added' => $result['added'], 'existing' => $result['existing'], 'feed' => $result['feed_url']])
            : __(':added added, :existing already listed (:pages pages)', ['added' => $result['added'], 'existing' => $result['existing'], 'pages' => $result['pages']]));
    }
}; ?>

<section class="w-full space-y-6">
    <x-pages::detail-header :back="route('sources.index')" :back-label="__('Sources')" :title="$source->name" />

    <form wire:submit="save" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-3 dark:border-neutral-700">
        <flux:input wire:model="name" :label="__('Name')" />
        <flux:input wire:model="url" :label="__('URL')" type="url" />
        <flux:input wire:model="notes" :label="__('Notes')" />
        <div class="flex items-center gap-3 md:col-span-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this source and everything found under it?') }}">{{ __('Delete') }}</flux:button>
            <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="text-sm underline">{{ __('Open') }} ↗</a>
            <flux:text class="ms-auto">{{ __('Created') }}: {{ $source->created_at->display() }}</flux:text>
        </div>
    </form>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" @if ($source->status === 'pending') wire:poll.5s="refreshStatus" @endif>
        <x-pages::status :status="$source->status" />
        <flux:text class="flex-1">{{ $source->status_message ?? '—' }}</flux:text>
        <flux:checkbox wire:model.live="readAsHtml" :label="__('Read as HTML list (do not look for a feed)')" />
        <flux:button wire:click="configure" size="sm" icon="sparkles">{{ __('Configure again') }}</flux:button>
    </div>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:button wire:click="fetchUpdates" variant="primary" icon="arrow-path">{{ __('Fetch updates') }}</flux:button>
        <flux:text>
            {{ __('Feed') }}:
            @if ($source->feed_url)
                <a href="{{ $source->feed_url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $source->feed_url }}</a>
            @else
                —
            @endif
        </flux:text>
        <flux:text class="ms-auto">{{ __('Fetched at') }}: {{ $source->fetched_at?->display() ?? '—' }}</flux:text>
    </div>

    <form wire:submit="saveList" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('HTML list settings') }}</flux:heading>
        <flux:text>{{ __('CSS selectors. Leave the item empty to read a feed instead. The next page is read only while the page just read had something new.') }}</flux:text>
        <div class="grid gap-3 md:grid-cols-5">
            <flux:input wire:model="list.item" :label="__('Item')" placeholder="table.table1 tr" />
            <flux:input wire:model="list.title" :label="__('Title link')" placeholder="td a" />
            <flux:input wire:model="list.date" :label="__('Date')" placeholder="time" />
            <flux:input wire:model="list.next" :label="__('Next page link')" placeholder='a[title="next page"]' />
            <flux:input wire:model="list.max_pages" :label="__('Max pages')" type="number" min="1" max="100" />
        </div>
        <flux:button type="submit">{{ __('Save') }}</flux:button>
    </form>

    <form wire:submit="saveJson" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('JSON list settings') }}</flux:heading>
        <flux:text>{{ __('For a page whose list is drawn by a script from a JSON file. With a URL here, the list is read from that file (newest first) instead of the feed or the HTML list. Path and keys use dot notation.') }}</flux:text>
        <div class="grid gap-3 md:grid-cols-6">
            <div class="md:col-span-2">
                <flux:input wire:model="json.url" :label="__('JSON URL')" type="url" placeholder="https://…/news-article.json" />
            </div>
            <flux:input wire:model="json.items" :label="__('Items path')" placeholder="news" />
            <flux:input wire:model="json.title" :label="__('Title key')" placeholder="title" />
            <flux:input wire:model="json.link" :label="__('Link key')" placeholder="url" />
            <flux:input wire:model="json.date" :label="__('Date key')" placeholder="date" />
            <flux:input wire:model="json.max_items" :label="__('Max items')" type="number" min="1" max="1000" />
        </div>
        <flux:button type="submit">{{ __('Save') }}</flux:button>
    </form>

    <form wire:submit="saveDocumentSettings" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Document settings') }}</flux:heading>
        <flux:text>{{ __('CSS selectors. The content element holds the body of one document and the date element its date; the remove selectors drop elements inside the body (share buttons, related links, navigation); the fixed text selectors pick the notices and copyright lines that are moved after the body. Left empty, the agent proposes them at the next fetch.') }}</flux:text>
        <div class="grid gap-3 md:grid-cols-4">
            <flux:input wire:model="documentSettings.content" :label="__('Content')" placeholder="article" />
            <flux:input wire:model="documentSettings.date" :label="__('Date')" placeholder="time" />
            <flux:input wire:model="documentSettings.remove" :label="__('Remove')" placeholder=".share, .related" />
            <flux:input wire:model="documentSettings.fixed_text" :label="__('Fixed text')" placeholder=".notice, .copyright" />
        </div>
        <div class="flex items-center gap-3">
            <flux:button type="submit">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="fetchDocuments" icon="document-arrow-down">{{ __('Fetch documents') }}</flux:button>
        </div>
    </form>

    <flux:heading size="lg">{{ __('Updates') }}</flux:heading>
    <x-pages::table :columns="[__('Title'), __('Published at'), __('Document')]" :empty="$source->updateEntries->isEmpty()">
        @foreach ($source->updateEntries()->with('document')->latest('published_at')->latest('id')->get() as $update)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('updates.show', $update) }}" class="underline" wire:navigate>{{ $update->title }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $update->published_at?->format('Y-m-d') }}</td>
                <td class="px-3 py-2">
                    @if ($update->document)
                        <a href="{{ route('documents.show', $update->document) }}" wire:navigate><x-pages::status :status="$update->document->status" /></a>
                    @elseif ($update->excluded_by !== null)
                        <flux:tooltip :content="__('Excluded by keyword: :keyword', ['keyword' => $update->excluded_by])"><x-pages::status status="excluded" /></flux:tooltip>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
