<?php

use App\Actions\DrawSpotCheck;
use App\Models\SpotCheck;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// 抜き取り点検 (Spot check): a day's documents, drawn from what the semantic filter measured, judged one at a time by a person — like this media, cannot tell, unlike it — with the keys 4 / 5 / 6. The likeness stays hidden until the verdict is given, so it cannot sway it.
new #[Title('抜き取り点検')] class extends Component {
    /** The day whose draw is shown (Y-m-d, display timezone), in the URL. */
    #[Url]
    public string $day = '';

    /** The spot check in view. */
    #[Url]
    public ?int $check = null;

    public function mount(): void
    {
        $this->day = $this->day !== '' ? $this->day : (string) (SpotCheck::query()->max('drawn_on') ?? $this->today());
        $this->check ??= $this->firstUndecided()?->id ?? $this->checks->first()?->id;
    }

    private function today(): string
    {
        return CarbonImmutable::now((string) config('app.display_timezone'))->toDateString();
    }

    // Draw today's documents; a day is drawn once.
    public function draw(DrawSpotCheck $draw): void
    {
        $result = $draw(CarbonImmutable::parse($this->today()));
        $this->day = $this->today();
        unset($this->checks, $this->days, $this->current);
        $this->check = $this->firstUndecided()?->id;

        Flux::toast(variant: $result['already'] ? 'warning' : 'success', text: $result['already']
            ? __('Today\'s spot check is already drawn.')
            : __(':drawn documents drawn from the :population the semantic filter measured today.', $result));
    }

    // Record the verdict on the document in view, then move on to the next one of the day; after the last, back to one not judged yet.
    public function decide(string $verdict): void
    {
        abort_unless(in_array($verdict, SpotCheck::VERDICTS, true), 422);
        $current = $this->current;

        if ($current === null) {
            return;
        }

        $current->update(['verdict' => $verdict, 'decided_by' => auth()->id(), 'decided_at' => now()]);
        $ids = $this->checks->pluck('id')->values();
        $next = $ids->get((int) $ids->search($current->id) + 1);
        unset($this->checks, $this->current);
        $this->check = $next ?? $this->firstUndecided()?->id ?? $current->id;
    }

    // The previous (-1) or next (+1) document of the day.
    public function move(int $step): void
    {
        $ids = $this->checks->pluck('id')->values();
        $index = $ids->search($this->check);
        $this->check = $ids->get(max(0, min($ids->count() - 1, ($index === false ? 0 : $index) + $step)));
        unset($this->current);
    }

    public function show(int $id): void
    {
        $this->check = $id;
        unset($this->current);
    }

    public function updatedDay(): void
    {
        unset($this->checks, $this->current);
        $this->check = $this->firstUndecided()?->id ?? $this->checks->first()?->id;
    }

    private function firstUndecided(): ?SpotCheck
    {
        return $this->checks->first(fn (SpotCheck $check): bool => $check->verdict === null);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SpotCheck> the day's draw, in the order drawn */
    #[Computed]
    public function checks()
    {
        return SpotCheck::query()->whereDate('drawn_on', $this->day)->with('document.source')->orderBy('id')->get();
    }

    #[Computed]
    public function current(): ?SpotCheck
    {
        return $this->checks->firstWhere('id', $this->check);
    }

    /** @return list<string> the days that have a draw, newest first */
    #[Computed]
    public function days(): array
    {
        return SpotCheck::query()->select('drawn_on')->distinct()->orderByDesc('drawn_on')->pluck('drawn_on')->map(fn ($day): string => CarbonImmutable::parse($day)->toDateString())->all();
    }

    // Poll while a translation is still on its way.
    public function refreshChecks(): void
    {
        unset($this->checks, $this->current);
    }
}; ?>

@php($labels = ['like' => __('Like this media'), 'unsure' => __('Cannot tell'), 'unlike' => __('Unlike this media')])
@php($keys = ['like' => '4', 'unsure' => '5', 'unlike' => '6'])
<section class="w-full space-y-6"
    @if ($this->checks->contains(fn ($check) => $check->title_ja === null && $check->translation_error === null)) wire:poll.3s="refreshChecks" @endif
    x-data
    x-on:keydown.window="
        if ($event.target.closest('input, textarea, select, [contenteditable]') || $event.metaKey || $event.ctrlKey || $event.altKey) return;
        const verdicts = { '4': 'like', '5': 'unsure', '6': 'unlike' };
        if (verdicts[$event.key]) { $event.preventDefault(); $wire.decide(verdicts[$event.key]); }
        else if ($event.key === 'ArrowLeft') { $event.preventDefault(); $wire.move(-1); }
        else if ($event.key === 'ArrowRight') { $event.preventDefault(); $wire.move(1); }
    ">
    <flux:heading size="xl">{{ __('Spot check') }}</flux:heading>
    <flux:text>{{ __('A few documents a day, drawn from what the semantic filter measured: some that passed, some just below the threshold, some far below. Judge each one by what you see, without its likeness, which shows once you have judged. Nothing waits for this: the verdicts are used to estimate what the filter misses.') }}</flux:text>

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="day" :label="__('Day drawn')" size="sm" class="w-40!">
            @foreach ($this->days as $option)
                <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
            @endforeach
            @if (! in_array($day, $this->days, true))
                <flux:select.option value="{{ $day }}">{{ $day }}</flux:select.option>
            @endif
        </flux:select>
        <flux:button wire:click="draw" icon="sparkles" size="sm">{{ __('Draw today\'s spot check') }}</flux:button>
        <flux:text class="ms-auto">{{ __(':decided of :total judged', ['decided' => $this->checks->whereNotNull('verdict')->count(), 'total' => $this->checks->count()]) }}</flux:text>
    </div>

    @if ($this->current)
        @php($current = $this->current)
        <div class="space-y-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700" wire:key="check-{{ $current->id }}">
            <div class="flex items-center justify-between gap-3 text-sm text-neutral-500">
                <span><x-pages::favicon :source="$current->document->source" /> {{ $current->document->source->name }}</span>
                <span>{{ $this->checks->search(fn ($check) => $check->id === $current->id) + 1 }} / {{ $this->checks->count() }}</span>
            </div>
            <flux:heading size="lg">{{ $current->title_ja ?? ($current->translation_error !== null ? $current->document->title : __('Translating…')) }}</flux:heading>
            <div class="text-sm text-neutral-500">{{ $current->document->title }}</div>
            @if ($current->summary_ja)
                <flux:text>{{ $current->summary_ja }}</flux:text>
            @endif
            <div class="flex flex-wrap gap-4 text-sm">
                <a href="{{ $current->document->url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ __('Primary source') }} ↗</a>
                <a href="{{ route('editorial.documents.show', $current->document) }}" target="_blank" class="underline">{{ __('Document') }} ↗</a>
            </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                <flux:button size="sm" icon="chevron-left" wire:click="move(-1)" :aria-label="__('Previous')" />
                @foreach ($labels as $verdict => $label)
                    <flux:button wire:click="decide('{{ $verdict }}')" :variant="$current->verdict === $verdict ? 'primary' : 'outline'">
                        <kbd class="me-1 rounded border border-current px-1 text-xs">{{ $keys[$verdict] }}</kbd> {{ $label }}
                    </flux:button>
                @endforeach
                <flux:button size="sm" icon="chevron-right" wire:click="move(1)" :aria-label="__('Next')" />
            </div>
            {{-- The likeness only once judged, so it cannot sway the verdict. --}}
            @if ($current->verdict !== null)
                <flux:text size="sm" class="text-neutral-500">
                    {{ __('The semantic filter, when drawn: likeness :likeness against a threshold of :threshold, :result.', ['likeness' => sprintf('%+.3f', $current->likeness), 'threshold' => sprintf('%+.2f', $current->threshold), 'result' => $current->passed ? __('let through') : __('left out')]) }}
                </flux:text>
            @endif
            <flux:text size="sm" class="text-neutral-500">{{ __('Keys: 4 like, 5 cannot tell, 6 unlike; ← → to move.') }}</flux:text>
        </div>
    @else
        <flux:text class="text-neutral-500">{{ __('Nothing drawn for this day yet.') }}</flux:text>
    @endif

    {{-- The day's draw at a glance: pick one to look at it again. --}}
    @if ($this->checks->isNotEmpty())
        <div class="divide-y divide-neutral-200 rounded-xl border border-neutral-200 dark:divide-neutral-700 dark:border-neutral-700">
            @foreach ($this->checks as $check)
                <button type="button" wire:click="show({{ $check->id }})" wire:key="row-{{ $check->id }}" class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm {{ $check->id === $this->check ? 'bg-neutral-100 dark:bg-neutral-800' : '' }}">
                    <span class="w-24 shrink-0">{{ $check->verdict !== null ? $labels[$check->verdict] : '—' }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ $check->title_ja ?? $check->document->title }}</span>
                </button>
            @endforeach
        </div>
    @endif
</section>
