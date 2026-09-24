<?php

use App\Actions\FetchUpdates;
use App\Actions\ReadDocument;
use App\Actions\RebuildMarkdown;
use App\Actions\ReviseDocumentSettings;
use App\Crawl\JsonList;
use App\Jobs\ConfigureSource;
use App\Jobs\FetchDocument;
use App\Jobs\ScreenDocument;
use App\Models\Source;
use App\Models\Document;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// 情報源 (Source) detail.
new #[Title('情報源')] class extends Component {
    public Source $source;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    public string $notes = '';

    /** @var array<string, string> HTML list settings, all CSS selectors except max_pages */
    public array $list = [];

    /** @var array<string, string> JSON list settings */
    public array $json = [];

    /** @var array<string, string> Document settings (CSS selectors) */
    public array $documentSettings = [];

    /** UI: 全文へのリンク — CSS selectors, one per line, tried in order */
    public string $fullTextLink = '';

    /** UI: 一覧の取得方法 — feed / html / json */
    public string $method = 'feed';

    // Fill the forms from the source.
    public function mount(): void
    {
        $this->name = $this->source->name;
        $this->url = $this->source->url;
        $this->notes = $this->source->notes ?? '';
        $this->fullTextLink = $this->source->full_text_link ?? '';
        $this->method = match (true) {
            ($this->source->json_list_settings['url'] ?? '') !== '' => 'json',
            ($this->source->html_list_settings['item'] ?? '') !== '' || $this->source->list_method === 'html' => 'html',
            default => 'feed',
        };

        $this->list = self::filled(self::blankList(), $this->source->html_list_settings);
        $this->json = self::filled(self::blankJson(), $this->source->json_list_settings);
        $this->documentSettings = self::filled(array_fill_keys(ReadDocument::DOCUMENT_SETTING_KEYS, ''), $this->source->document_settings);
    }

    /** @return array<string, string> the empty HTML list form */
    private static function blankList(): array
    {
        return ['item' => '', 'title' => '', 'date' => '', 'next' => '', 'max_pages' => (string) ConfigureSource::DEFAULT_MAX_PAGES];
    }

    /** @return array<string, string> the empty JSON list form */
    private static function blankJson(): array
    {
        return ['url' => '', 'items' => '', 'title' => 'title', 'link' => 'url', 'date' => '', 'max_items' => (string) JsonList::DEFAULT_MAX_ITEMS];
    }

    /**
     * A form's fields with the saved values put in, as strings.
     *
     * @param  array<string, string>  $blank
     * @param  array<string, mixed>|null  $saved
     * @return array<string, string>
     */
    private static function filled(array $blank, ?array $saved): array
    {
        return array_map(strval(...), [...$blank, ...array_intersect_key($saved ?? [], $blank)]);
    }

    // Save the JSON list settings; an empty URL clears them.
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

        // Saving the JSON list drops the HTML list settings.
        $this->source->update(['html_list_settings' => null, 'json_list_settings' => ($validated['url'] ?? '') !== ''
            ? [...$validated, 'items' => (string) ($validated['items'] ?? ''), 'date' => (string) ($validated['date'] ?? ''), 'max_items' => (int) $validated['max_items']]
            : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Save 全文へのリンク.
    public function saveFullTextLink(): void
    {
        $this->validate(['fullTextLink' => ['nullable', 'string', 'max:1000']]);
        $this->source->update(['full_text_link' => trim($this->fullTextLink) !== '' ? trim($this->fullTextLink) : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Save the document settings; an empty content selector clears them.
    public function saveDocumentSettings(): void
    {
        $validated = $this->validate([
            'documentSettings.content' => ['nullable', 'string', 'max:255'],
            'documentSettings.date' => ['nullable', 'string', 'max:255'],
            'documentSettings.remove' => ['nullable', 'string', 'max:1000'],
            'documentSettings.fixed_text' => ['nullable', 'string', 'max:1000'],
        ])['documentSettings'];

        $this->source->update(['document_settings' => ($validated['content'] ?? '') !== ''
            ? array_map(fn (?string $value): string => (string) $value, $validated)
            : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Queue the unfetched and failed documents, or all but those fetching; excluded ones are skipped.
    public function fetchDocuments(bool $all = false): void
    {
        $documents = $this->source->documents()->whereNull('excluded_by')
            ->where(fn ($query) => $all ? $query->whereNull('status')->orWhere('status', '!=', 'fetching') : $query->whereNull('status')->orWhere('status', 'failed'))->get();
        $documents->each(fn (Document $document) => FetchDocument::queueFor($document));

        Flux::toast(variant: 'success', text: __(':count documents queued.', ['count' => $documents->count()]));
    }

    /**
     * The documents with a short body (UI: 本文が短い).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Document>
     */
    #[Computed]
    public function shortDocuments()
    {
        return $this->source->documents()->withShortBody()->orderBy('id')->get();
    }

    // Have the agent propose document settings from a short document, and screen the cured ones again.
    public function proposeDocumentSettings(ReviseDocumentSettings $revise): void
    {
        $document = $this->shortDocuments->first(fn (Document $document) => $document->format === 'html' && $document->original_path !== null && Storage::disk('local')->exists((string) $document->original_path));

        if ($document === null) {
            Flux::toast(variant: 'warning', text: __('No short HTML document with its original on disk to propose from.'));

            return;
        }

        try {
            $result = $revise($this->source, $document);
        } catch (\Throwable $exception) {
            Flux::toast(variant: 'danger', duration: 8000, text: $exception->getMessage());

            return;
        }

        Document::query()->whereIn('id', $result['grown'])->get()->each(fn (Document $grown) => ScreenDocument::queueFor($grown));
        $this->mount();
        unset($this->shortDocuments);

        Flux::toast(variant: 'success', duration: 8000, text: __('Document settings proposed by the agent and verified on ":title" (content: :content, :count characters). :rebuilt documents rebuilt, :failed failed; :grown no longer short, screened again.', ['title' => $document->title, 'content' => $result['settings']['content'], 'count' => $result['chars'], 'rebuilt' => $result['rebuilt'], 'failed' => $result['failed'], 'grown' => count($result['grown'])]));
    }

    // Rebuild every document's Markdown from its original on disk.
    public function rebuildMarkdown(RebuildMarkdown $rebuild): void
    {
        $result = $rebuild($this->source);
        unset($this->shortDocuments);

        Flux::toast(variant: $result['failed'] === 0 ? 'success' : 'warning', duration: 8000, text: __(':rebuilt documents rebuilt, :failed could not be read with the current settings (fetch them again).', $result));
    }


    // Save the HTML list settings; an empty item clears them.
    public function saveList(): void
    {
        $validated = $this->validate([
            'list.item' => ['nullable', 'string', 'max:255'],
            'list.title' => ['nullable', 'string', 'max:255'],
            'list.date' => ['nullable', 'string', 'max:255'],
            'list.next' => ['nullable', 'string', 'max:255'],
            'list.max_pages' => ['required', 'integer', 'min:1', 'max:100'],
        ])['list'];

        // Saving the HTML list drops the JSON list settings.
        $this->source->update(['json_list_settings' => null, 'html_list_settings' => $validated['item'] !== '' && $validated['item'] !== null
            ? [...$validated, 'max_pages' => (int) $validated['max_pages']]
            : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Save name, URL and notes.
    public function save(): void
    {
        $validated = $this->validate();
        $this->source->update([...$validated, 'notes' => $this->notes !== '' ? $this->notes : null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    // Delete the source and, by cascade, everything under it.
    public function delete(): void
    {
        $this->source->delete();

        $this->redirectRoute('editorial.sources.index', navigate: true);
    }

    // Remember the chosen list method.
    public function updatedMethod(string $value): void
    {
        $this->source->update(['list_method' => $value]);
    }

    // Switch to the feed: drop the list settings and configure again.
    public function useFeed(): void
    {
        $this->source->update(['html_list_settings' => null, 'json_list_settings' => null, 'list_method' => 'feed']);
        $this->list = self::blankList();
        $this->json = self::blankJson();
        $this->configure();
    }

    // Queue ConfigureSource again.
    public function configure(): void
    {
        $this->source->update(['status' => 'configuring', 'status_message' => null]);
        ConfigureSource::dispatch($this->source);

        Flux::toast(variant: 'success', text: __('Configuration queued.'));
    }

    // Polled while configuring; refill the forms once done.
    public function refreshStatus(): void
    {
        $this->source->refresh();

        if ($this->source->status !== 'configuring') {
            $this->mount();
        }
    }

    // Read the update list (UI: 更新リストを取得).
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
    <x-pages::detail-header :back="route('editorial.sources.index')" :back-label="__('Sources')" :title="$source->name" />

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

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" @if ($source->status === 'configuring') wire:poll.5s="refreshStatus" @endif>
        <x-pages::status :status="$source->status" />
        <flux:text class="flex-1">{{ $source->status_message ?? '—' }}</flux:text>
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
        <flux:text class="ms-auto">{{ __('Updates fetched at') }}: {{ $source->updates_fetched_at?->display() ?? '—' }}</flux:text>
    </div>

    {{-- 一覧の取得方法: only the chosen method's settings are shown. --}}
    <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:radio.group wire:model.live="method" :label="__('List method')" variant="segmented" size="sm">
            <flux:radio value="feed" :label="__('Feed')" />
            <flux:radio value="html" :label="__('HTML list')" />
            <flux:radio value="json" :label="__('JSON list')" />
        </flux:radio.group>

        @if ($method === 'feed')
            <flux:text>{{ __('The RSS or Atom feed of the site, found when the source is configured.') }}</flux:text>
            @if (($source->html_list_settings['item'] ?? '') !== '' || ($source->json_list_settings['url'] ?? '') !== '')
                <flux:text>{{ __('The list is still read with the HTML or JSON list settings; to read the feed instead, drop them and look for the feed again:') }}</flux:text>
                <flux:button type="button" wire:click="useFeed" wire:confirm="{{ __('Drop the HTML and JSON list settings and look for a feed?') }}">{{ __('Read the feed') }}</flux:button>
            @endif
            <form wire:submit="saveFullTextLink" class="space-y-3">
                <flux:textarea wire:model="fullTextLink" :label="__('Full text link')" rows="2" placeholder="#latexml-download-link&#10;a.download-pdf" />
                <flux:text>{{ __('For a feed that gives a summary of every document. Filled in, a new document is screened on the summary from the feed without being fetched, and only once it is adopted is its page fetched and the full text it links to read. CSS selectors of the link on the document\'s page, one per line, tried in order.') }}</flux:text>
                <flux:button type="submit">{{ __('Save') }}</flux:button>
            </form>
        @elseif ($method === 'html')
            <form wire:submit="saveList" class="space-y-3">
                <flux:text>{{ __('CSS selectors. Left empty, the agent proposes them when the source is configured again. The next page is read only while the page just read had something new.') }}</flux:text>
                <div class="grid gap-3 md:grid-cols-5">
                    <flux:input wire:model="list.item" :label="__('Item')" placeholder="table.table1 tr" />
                    <flux:input wire:model="list.title" :label="__('Title link')" placeholder="td a" />
                    <flux:input wire:model="list.date" :label="__('Date')" placeholder="time" />
                    <flux:input wire:model="list.next" :label="__('Next page link')" placeholder='a[title="next page"]' />
                    <flux:input wire:model="list.max_pages" :label="__('Max pages')" type="number" min="1" max="100" />
                </div>
                <flux:button type="submit">{{ __('Save') }}</flux:button>
            </form>
        @else
            <form wire:submit="saveJson" class="space-y-3">
                <flux:text>{{ __('For a page whose list is drawn by a script from a JSON file. The list is read from that file (newest first). Path and keys use dot notation.') }}</flux:text>
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
        @endif
    </div>

    <form wire:submit="saveDocumentSettings" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Document settings') }}</flux:heading>
        <flux:text>{{ __('CSS selectors. The content element holds the body of one document and the date element its date; the remove selectors drop elements inside the body (share buttons, related links, navigation); the fixed text selectors pick the notices and copyright lines that are moved after the body. Left empty, the agent proposes them at the next fetch.') }}</flux:text>
        <div class="grid gap-3 md:grid-cols-4">
            <flux:input wire:model="documentSettings.content" :label="__('Content')" placeholder="article" />
            <flux:input wire:model="documentSettings.date" :label="__('Date')" placeholder="time" />
            <flux:input wire:model="documentSettings.remove" :label="__('Remove')" placeholder=".share, .related" />
            <flux:input wire:model="documentSettings.fixed_text" :label="__('Fixed text')" placeholder=".notice, .copyright" />
        </div>
        {{-- 本文が短い documents, with a re-proposal. --}}
        @if ($this->shortDocuments->isNotEmpty())
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __(':count fetched documents have a short body (under :chars characters)', ['count' => $this->shortDocuments->count(), 'chars' => \App\Models\Document::SHORT_BODY_CHARS]) }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('The content selector may catch a teaser or a header instead of the body. Check one, then fix the selectors above and rebuild the Markdown, or have the agent propose settings again from the original of a short document:') }}
                    @foreach ($this->shortDocuments->take(3) as $short)
                        <a href="{{ route('editorial.documents.show', $short) }}" class="underline" wire:navigate>{{ mb_strimwidth($short->title, 0, 40, '…') }}</a>（{{ mb_strlen((string) $short->markdown) }}）@if (! $loop->last)、@endif
                    @endforeach
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button wire:click="proposeDocumentSettings" size="sm" icon="sparkles">{{ __('Propose settings again from a short document') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif
        <div class="flex items-center gap-3">
            <flux:button type="submit">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="fetchDocuments" icon="document-arrow-down">{{ __('Fetch documents') }}</flux:button>
            <flux:button type="button" wire:click="rebuildMarkdown" icon="document-text">{{ __('Rebuild Markdown from the originals') }}</flux:button>
            <flux:button type="button" wire:click="fetchDocuments(true)" icon="arrow-path" wire:confirm="{{ __('Fetch all :count documents of this source again? Each page is requested from the site once more.', ['count' => $source->documents()->whereNull('excluded_by')->count()]) }}">{{ __('Fetch all documents again') }}</flux:button>
        </div>
    </form>

    {{-- Excluded and failed documents, with the reason. --}}
    @php $notFetched = $source->documents()->where(fn ($query) => $query->whereNotNull('excluded_by')->orWhere('status', 'failed'))->latest('published_at')->latest('id')->get(); @endphp
    <flux:heading size="lg">{{ __('Documents not fetched') }}</flux:heading>
    <x-pages::table :columns="[__('Title'), __('Published on'), __('Status'), __('Reason')]" :empty="$notFetched->isEmpty()">
        @foreach ($notFetched as $document)
            <tr>
                <td class="px-3 py-2"><x-pages::short-title :title="$document->title" :href="route('editorial.documents.show', $document)" /></td>
                <td class="px-3 py-2 text-neutral-500">{{ $document->published_at?->format('Y-m-d') }}</td>
                @if ($document->excluded_by !== null)
                    <td class="px-3 py-2"><x-pages::status status="excluded" /></td>
                    <td class="px-3 py-2 text-neutral-500">{{ __('Excluded by keyword: :keyword', ['keyword' => $document->excluded_by]) }}</td>
                @else
                    <td class="px-3 py-2"><x-pages::status :status="$document->status" /></td>
                    <td class="px-3 py-2 text-red-600 dark:text-red-400">{{ $document->status_message ?? '—' }}</td>
                @endif
            </tr>
        @endforeach
    </x-pages::table>
</section>
