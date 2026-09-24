<?php

use App\Actions\DrawSpotCheck;
use App\Actions\SpotCheckFigures;
use App\Models\SpotCheck;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// 抜き取り点検 (Spot check): a day's documents, drawn from what the semantic filter measured, judged one at a time by a person — like this media, cannot tell, unlike it — with the keys 4 / 5 / 6. The likeness stays hidden until the verdict is given, so it cannot sway it. Once all are judged the day is closed with 確定 (Enter), and a closed day cannot be changed until it is reopened.
new #[Title('抜き取り点検')] class extends Component {
    /** The day whose draw is shown (Y-m-d, display timezone), in the URL. */
    #[Url]
    public string $day = '';

    /** The spot check in view; none once every one of the day is judged (the day is then to be confirmed, or is confirmed). */
    #[Url]
    public ?int $check = null;

    public function mount(): void
    {
        $this->day = $this->day !== '' ? $this->day : (string) (SpotCheck::query()->max('drawn_on') ?? $this->today());
        $this->check ??= $this->isConfirmed() ? null : $this->firstUndecided()?->id;
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
        $this->check = $this->isConfirmed() ? null : $this->firstUndecided()?->id;

        Flux::toast(variant: $result['already'] ? 'warning' : 'success', text: $result['already']
            ? __('Today\'s spot check is already drawn.')
            : __(':drawn documents drawn from the :population the semantic filter measured today.', $result));
    }

    // Record the verdict on the document in view, then move on to the next one of the day; after the last, back to one not judged yet.
    public function decide(string $verdict): void
    {
        abort_unless(in_array($verdict, SpotCheck::VERDICTS, true), 422);
        $current = $this->current;

        if ($current === null || $this->isConfirmed()) {
            return;
        }

        $current->update(['verdict' => $verdict, 'decided_by' => auth()->id(), 'decided_at' => now()]);
        $ids = $this->checks->pluck('id')->values();
        $next = $ids->get((int) $ids->search($current->id) + 1);
        unset($this->checks, $this->current);
        // After the last one: back to one not judged yet, or, all judged, to the confirmation.
        $this->check = $next ?? $this->firstUndecided()?->id;
    }

    // Close the day: every document of it judged, the verdicts are final until the day is reopened.
    public function confirm(): void
    {
        if ($this->checks->isEmpty() || $this->firstUndecided() !== null || $this->isConfirmed()) {
            return;
        }

        SpotCheck::query()->whereDate('drawn_on', $this->day)->update(['confirmed_at' => now(), 'confirmed_by' => auth()->id()]);
        unset($this->checks, $this->current, $this->figures);
        $this->check = null;

        Flux::toast(variant: 'success', text: __('The spot check of :day is confirmed.', ['day' => $this->day]));
    }

    // Reopen a confirmed day, to change a verdict.
    public function reopen(): void
    {
        SpotCheck::query()->whereDate('drawn_on', $this->day)->update(['confirmed_at' => null, 'confirmed_by' => null]);
        unset($this->checks, $this->current);
    }

    private function isConfirmed(): bool
    {
        return $this->checks->isNotEmpty() && $this->checks->first()->confirmed_at !== null;
    }

    // The previous (-1) or next (+1) document of the day; past the last one, the confirmation.
    public function move(int $step): void
    {
        $ids = $this->checks->pluck('id')->values();
        $index = $this->check === null ? $ids->count() : $ids->search($this->check);
        $target = ($index === false ? 0 : $index) + $step;
        $this->check = $target >= $ids->count() && $this->firstUndecided() === null ? null : $ids->get(max(0, min($ids->count() - 1, $target)));
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
        $this->check = $this->isConfirmed() ? null : $this->firstUndecided()?->id;
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

    /**
     * 結果の数字: what the confirmed days say about the semantic filter.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function figures(): array
    {
        return app(SpotCheckFigures::class)();
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
        else if ($event.key === 'Enter' && $event.target.closest('button, a') === null) { $event.preventDefault(); $wire.confirm(); }
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
    @elseif ($this->checks->isNotEmpty())
        {{-- Every one judged: the day is to be confirmed, or is confirmed. --}}
        @php($first = $this->checks->first())
        <div class="space-y-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading size="lg">{{ $first->confirmed_at !== null ? __('The spot check of this day is confirmed') : __('Every document of this day is judged') }}</flux:heading>
            <flux:text>{{ collect($labels)->map(fn ($label, $verdict) => $label.' '.$this->checks->where('verdict', $verdict)->count())->implode(' / ') }}</flux:text>
            @if ($first->confirmed_at !== null)
                <flux:text size="sm" class="text-neutral-500">{{ __('Confirmed :when by :who', ['when' => $first->confirmed_at->display(), 'who' => $first->confirmer?->name ?? '—']) }}</flux:text>
                <flux:button size="sm" wire:click="reopen">{{ __('Reopen') }}</flux:button>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <flux:button size="sm" icon="chevron-left" wire:click="move(-1)" :aria-label="__('Previous')" />
                    <flux:button variant="primary" wire:click="confirm"><kbd class="me-1 rounded border border-current px-1 text-xs">Enter</kbd> {{ __('Confirm') }}</flux:button>
                </div>
                <flux:text size="sm" class="text-neutral-500">{{ __('Confirm to close the day. The verdicts cannot be changed until it is reopened.') }}</flux:text>
            @endif
        </div>
    @else
        <flux:text class="text-neutral-500">{{ __('Nothing drawn for this day yet.') }}</flux:text>
    @endif

    {{-- The day's draw at a glance: pick one to look at it again. --}}
    @if ($this->checks->isNotEmpty())
        <div class="divide-y divide-neutral-200 rounded-xl border border-neutral-200 dark:divide-neutral-700 dark:border-neutral-700">
            @foreach ($this->checks as $check)
                <button type="button" wire:click="show({{ $check->id }})" @disabled($check->confirmed_at !== null) wire:key="row-{{ $check->id }}" class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm {{ $check->id === $this->check ? 'bg-neutral-100 dark:bg-neutral-800' : '' }}">
                    <span class="w-24 shrink-0">{{ $check->verdict !== null ? $labels[$check->verdict] : '—' }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ $check->title_ja ?? $check->document->title }}</span>
                </button>
            @endforeach
        </div>
    @endif

    {{-- 結果の数字: the confirmed days only, every drawn document weighted by the documents of its stratum it stands for. --}}
    @php($figures = $this->figures)
    @php($percent = fn (?float $share): string => $share === null ? '—' : number_format(100 * $share, 0).'%')
    <div class="space-y-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Figures (confirmed days)') }}</flux:heading>
        <flux:text>{{ __(':days days confirmed, :checks documents drawn, :judged judged like or unlike (cannot tell is left out). Each document stands for the documents of its stratum that day, so the rates are for the whole day, not the draw.', ['days' => $figures['days'], 'checks' => $figures['checks'], 'judged' => $figures['judged']]) }}</flux:text>
        @if ($figures['checks'] > 0)
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                    <div class="text-sm text-neutral-500">{{ __('Like this media, but left out') }}</div>
                    <div class="text-3xl font-semibold">{{ $percent($figures['missed_like']) }}</div>
                    <div class="text-xs text-neutral-500">{{ __('Of the documents like this media, the share the filter left out: what never reaches an article.') }}</div>
                </div>
                <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                    <div class="text-sm text-neutral-500">{{ __('Unlike this media, but let through') }}</div>
                    <div class="text-3xl font-semibold">{{ $percent($figures['passed_unlike']) }}</div>
                    <div class="text-xs text-neutral-500">{{ __('Of the documents the filter let through, the share unlike this media: what the screening pays for.') }}</div>
                </div>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="text-neutral-500">
                    <tr>@foreach ([__('Stratum'), __('Drawn'), __('Like this media'), __('Cannot tell'), __('Unlike this media'), __('Agreed with the filter')] as $column)<th class="px-2 py-1 font-medium">{{ $column }}</th>@endforeach</tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($figures['strata'] as $row)
                        <tr>
                            <td class="px-2 py-1">{{ ['passed' => __('Passed'), 'near' => __('Just below the threshold'), 'far' => __('Far below')][$row['stratum']] }}</td>
                            <td class="px-2 py-1">{{ $row['drawn'] }}</td>
                            <td class="px-2 py-1">{{ $row['like'] }}</td>
                            <td class="px-2 py-1">{{ $row['unsure'] }}</td>
                            <td class="px-2 py-1">{{ $row['unlike'] }}</td>
                            <td class="px-2 py-1">{{ $percent($row['agreed']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div>
                <flux:heading>{{ __('If the threshold were…') }}</flux:heading>
                <table class="mt-2 w-full text-left text-sm">
                    <thead class="text-neutral-500">
                        <tr>@foreach ([__('Threshold'), __('Let through a day'), __('Of the documents like this media, kept')] as $column)<th class="px-2 py-1 font-medium">{{ $column }}</th>@endforeach</tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($figures['thresholds'] as $row)
                            <tr class="{{ abs($row['threshold'] - \App\Models\EditorialPolicy::likenessThreshold()) < 0.0001 ? 'font-semibold' : '' }}">
                                <td class="px-2 py-1">{{ sprintf('%+.2f', $row['threshold']) }}@if (abs($row['threshold'] - \App\Models\EditorialPolicy::likenessThreshold()) < 0.0001) （{{ __('now') }}）@endif</td>
                                <td class="px-2 py-1">{{ __('about :count', ['count' => number_format($row['passed_per_day'])]) }}</td>
                                <td class="px-2 py-1">{{ $percent($row['like_kept']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <flux:text size="sm" class="text-neutral-500">{{ __('Few verdicts make rough figures: one more document judged like can move a rate by tens of points. Read them as a direction until a few weeks are confirmed.') }}</flux:text>
        @endif
    </div>
</section>
