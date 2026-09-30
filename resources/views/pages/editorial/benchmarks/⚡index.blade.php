<?php

use App\Jobs\CompareDocumentsWithBenchmarks;
use App\Jobs\ConfigureSource;
use App\Models\Document;
use App\Models\Source;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

// ベンチマーク (Benchmarks): sources flagged as benchmarks, whose documents are kept as embeddings only.
new #[Title('ベンチマーク')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|url|max:2048')]
    public string $url = '';

    public string $notes = '';

    // Add a benchmark and queue its configuration, as for a source.
    public function add(): void
    {
        $validated = $this->validate();
        $benchmark = Source::create([...$validated, 'notes' => $this->notes !== '' ? $this->notes : null, 'is_benchmark' => true]);
        ConfigureSource::dispatch($benchmark);
        $this->reset('name', 'url', 'notes');
        unset($this->benchmarks);

        Flux::toast(variant: 'success', text: __('Configuration queued.'));
    }

    // Queue the comparison of every embedded document of the sources.
    public function compareAgain(): void
    {
        CompareDocumentsWithBenchmarks::dispatch();

        Flux::toast(variant: 'success', text: __('Comparison queued.'));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Source> the benchmarks with document counts */
    #[Computed]
    public function benchmarks()
    {
        return Source::query()->where('is_benchmark', true)
            ->withCount([
                'documents',
                'documents as compared_documents_count' => fn ($query) => $query->whereNull('excluded_by')->whereHas('embedding')->whereRaw('coalesce(published_at, created_at) >= ?', [now()->subDays(Source::BENCHMARK_WINDOW_DAYS)]),
                'documents as failed_documents_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->orderBy('name')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Document> the benchmarks' latest documents */
    #[Computed]
    public function documents()
    {
        return Document::query()->fromBenchmarks()->with('source')->latest('id')->limit(50)
            ->get(['id', 'source_id', 'title', 'url', 'published_at', 'excluded_by', 'status', 'status_message']);
    }

    /** Documents of the sources with a benchmark similarity. */
    #[Computed]
    public function comparedDocuments(): int
    {
        return Document::query()->whereNotNull('benchmark_similarity')->count();
    }
}; ?>

<section class="w-full space-y-6" @if ($this->documents->contains('status', 'fetching')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Benchmarks') }}</flux:heading>

    <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:text>{{ __('Secondary-source sites that show what is being written about now. A benchmark is read like a source (feed, JSON list or HTML list; the title filter leaves ads and notices out) and its documents are fetched, but only their embeddings are kept: neither the original nor the body is recorded. Every document of the sources the semantic filter measures is compared with the benchmarks\' documents of the last :days days, and its similarity to the nearest document of each benchmark is recorded (benchmark similarity).', ['days' => Source::BENCHMARK_WINDOW_DAYS]) }}</flux:text>
        <flux:text>{{ __('For now the benchmark similarity is only recorded: it decides nothing. Once enough is recorded it is set against the verdicts of the spot check before a threshold is chosen.') }}</flux:text>
    </div>

    <form wire:submit="add" class="grid gap-3 rounded-xl border border-neutral-200 p-4 md:grid-cols-4 dark:border-neutral-700">
        <flux:input wire:model="name" :label="__('Name')" />
        <flux:input wire:model="url" :label="__('URL')" type="url" />
        <flux:input wire:model="notes" :label="__('Notes')" />
        <div class="flex items-end">
            <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Name'), __('Status'), __('Documents'), __('Documents compared with'), __('Failed fetches'), __('Updates fetched at')]" :empty="$this->benchmarks->isEmpty()">
        @foreach ($this->benchmarks as $benchmark)
            <tr wire:key="benchmark-{{ $benchmark->id }}">
                <td class="px-3 py-2">
                    <span class="inline-flex items-center gap-2">
                        <x-pages::favicon :source="$benchmark" />
                        <a href="{{ route('editorial.sources.show', $benchmark) }}" class="underline" wire:navigate>{{ $benchmark->name }}</a>@if ($benchmark->notes) <span class="text-neutral-500">{{ $benchmark->notes }}</span>@endif
                        <flux:tooltip :content="$benchmark->url">
                            <a href="{{ $benchmark->url }}" target="_blank" rel="noopener noreferrer" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"><flux:icon.arrow-top-right-on-square variant="micro" /></a>
                        </flux:tooltip>
                    </span>
                </td>
                <td class="px-3 py-2"><x-pages::status :status="$benchmark->status" /></td>
                <td class="px-3 py-2">{{ $benchmark->documents_count }}</td>
                <td class="px-3 py-2">{{ $benchmark->compared_documents_count }}</td>
                <td class="px-3 py-2 {{ $benchmark->failed_documents_count > 0 ? 'text-red-600 dark:text-red-400' : 'text-neutral-500' }}">{{ $benchmark->failed_documents_count }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-neutral-500">{{ $benchmark->updates_fetched_at?->display() ?? '—' }}</td>
            </tr>
        @endforeach
    </x-pages::table>

    <div class="flex flex-wrap items-center gap-3">
        <flux:text size="sm" class="text-neutral-500">{{ __(':count documents compared.', ['count' => $this->comparedDocuments]) }}</flux:text>
        <flux:button size="sm" icon="arrow-path" wire:click="compareAgain">{{ __('Compare every embedded document again') }}</flux:button>
    </div>

    <div class="space-y-2">
        <flux:heading size="lg">{{ __('Latest benchmark documents') }}</flux:heading>
        <x-pages::table :columns="[__('Benchmark'), __('Title'), __('Status'), __('Published on')]" :empty="$this->documents->isEmpty()">
            @foreach ($this->documents as $document)
                <tr wire:key="document-{{ $document->id }}" class="{{ $document->excluded_by !== null ? 'text-neutral-400' : '' }}">
                    <td class="whitespace-nowrap px-3 py-1">{{ $document->source->name }}</td>
                    <td class="px-3 py-1"><a href="{{ $document->url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $document->title }}</a></td>
                    <td class="px-3 py-1">
                        @if ($document->excluded_by !== null)
                            <flux:tooltip :content="__('Excluded by keyword: :keyword', ['keyword' => $document->excluded_by])"><x-pages::status status="excluded" /></flux:tooltip>
                        @elseif ($document->status !== null)
                            <flux:tooltip :content="$document->status_message ?? ''"><x-pages::status :status="$document->status" /></flux:tooltip>
                        @else
                            —
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-3 py-1 text-neutral-500">{{ $document->published_at?->format('Y-m-d') ?? '—' }}</td>
                </tr>
            @endforeach
        </x-pages::table>
    </div>
</section>
