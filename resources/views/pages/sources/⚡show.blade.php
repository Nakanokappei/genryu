<?php

use App\Actions\FetchUpdates;
use App\Jobs\ConfigureSource;
use App\Models\Source;
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

    public function mount(): void
    {
        $this->name = $this->source->name;
        $this->url = $this->source->url;
        $this->notes = $this->source->notes ?? '';

        foreach ($this->source->list_config ?? [] as $key => $value) {
            if (array_key_exists($key, $this->list)) {
                $this->list[$key] = (string) $value;
            }
        }
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
            <flux:text class="ms-auto">{{ __('Created') }}: {{ $source->created_at->format('Y-m-d H:i') }}</flux:text>
        </div>
    </form>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" @if ($source->status === 'pending') wire:poll.5s="refreshStatus" @endif>
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
        <flux:text class="ms-auto">{{ __('Fetched at') }}: {{ $source->fetched_at?->format('Y-m-d H:i') ?? '—' }}</flux:text>
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

    <flux:heading size="lg">{{ __('Updates') }}</flux:heading>
    <x-pages::table :columns="[__('Title'), __('Published at')]" :empty="$source->updateEntries->isEmpty()">
        @foreach ($source->updateEntries()->latest('published_at')->latest('id')->get() as $update)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('updates.show', $update) }}" class="underline" wire:navigate>{{ $update->title }}</a></td>
                <td class="px-3 py-2 text-neutral-500">{{ $update->published_at?->format('Y-m-d') }}</td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
