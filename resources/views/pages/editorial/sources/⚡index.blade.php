<?php

use App\Actions\ApplyTitleFilter;
use App\Jobs\ConfigureSource;
use App\Livewire\PagedList;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\Source;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;

// 情報源 (Sources): the sources, adding one, and the title filter.
new #[Title('情報源')] class extends PagedList {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    public string $notes = '';

    // The title filter's rules (UI: 除外キーワード).
    public string $excludeKeywords = '';

    // Load the title filter.
    public function mount(): void
    {
        $this->excludeKeywords = EditorialPolicy::bodyFor('title_filter');
    }

    // Save the title filter and apply it to every listed document.
    public function saveTitleFilter(ApplyTitleFilter $apply): void
    {
        EditorialPolicy::query()->updateOrCreate(['layer' => 'title_filter'], ['body' => $this->excludeKeywords]);
        $result = $apply();

        Flux::toast(variant: 'success', duration: 8000, text: __('Saved. :excluded documents newly excluded, :restored no longer excluded.', $result));
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Source> with document, failed and short-body counts */
    #[Computed]
    public function sources()
    {
        return Source::query()
            ->withCount([
                'documents',
                'documents as failed_documents_count' => fn ($query) => $query->where('status', 'failed'),
                'documents as short_documents_count' => fn ($query) => $query->withShortBody(),
            ])
            ->latest()->orderByDesc('id')->paginate($this->rowsPerPage());
    }

    // Add a source and queue its configuration.
    public function add(): void
    {
        $validated = $this->validate();
        $source = Source::create([...$validated, 'notes' => $this->notes !== '' ? $this->notes : null]);
        ConfigureSource::dispatch($source);
        $this->reset('name', 'url', 'notes');
        unset($this->sources);

        Flux::toast(variant: 'success', text: __('Configuration queued.'));
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Sources') }}</flux:heading>

    <form wire:submit="add" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-4 dark:border-neutral-700">
        <flux:input wire:model="name" :label="__('Name')" />
        <flux:input wire:model="url" :label="__('URL')" type="url" />
        <flux:input wire:model="notes" :label="__('Notes')" />
        <div class="flex items-end">
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Name'), __('Status'), __('Documents'), __('Failed fetches'), __('Short bodies'), __('Created')]" :empty="$this->sources->isEmpty()">
        @foreach ($this->sources as $source)
            <tr>
                <td class="px-3 py-2">
                    <span class="inline-flex items-center gap-2">
                        <x-pages::favicon :source="$source" />
                        <a href="{{ route('editorial.sources.show', $source) }}" class="underline" wire:navigate>{{ $source->name }}</a>
                        {{-- Link to the site, the URL as tooltip. --}}
                        <flux:tooltip :content="$source->url">
                            <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"><flux:icon.arrow-top-right-on-square variant="micro" /></a>
                        </flux:tooltip>
                    </span>
                </td>
                <td class="px-3 py-2"><x-pages::status :status="$source->status" /></td>
                <td class="px-3 py-2">{{ $source->documents_count }}</td>
                <td class="px-3 py-2 {{ $source->failed_documents_count > 0 ? 'text-red-600 dark:text-red-400' : 'text-neutral-500' }}">{{ $source->failed_documents_count }}</td>
                <td class="px-3 py-2 {{ $source->short_documents_count > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-500' }}">{{ $source->short_documents_count }}</td>
                <td class="px-3 py-2 text-neutral-500">{{ $source->created_at->format('Y-m-d') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
    <x-pages::pagination :paginator="$this->sources" />

    {{-- タイトルフィルタ --}}
    <form wire:submit="saveTitleFilter" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }} — {{ __('Title filter') }}</flux:heading>
        <flux:text>{{ __('One setting for every source, applied to the titles when an update list is read, before any document is fetched: what is listed but not fetched. Saving applies the rules to every document already listed as well. The content of the documents is judged on the Documents screen.') }}</flux:text>
        <flux:textarea wire:model="excludeKeywords" :label="__('Exclude keywords')" rows="8" placeholder="採用情報&#10;寄稿; 掲載&#10;株式; 取得; 子会社化" class="font-mono" />
        <flux:text size="sm">{{ __('One rule per line: a document whose title contains the word is listed but not fetched. Several words on one line, separated by semicolons, make one rule that needs all of them (掲載 alone would take real news with it; 寄稿; 掲載 does not).') }}</flux:text>
        <flux:text size="sm">{{ __('Words are matched whole (serving is not found in observing); end a word with * to let it go on (memoriz* finds memorize and memorization). A word in Chinese, Japanese or Korean is found anywhere in the text.') }}</flux:text>
        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
