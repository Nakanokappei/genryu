<?php

use App\Actions\ScheduleArticles;
use App\Livewire\PagedList;
use App\Models\Article;
use App\Models\ScheduleSetting;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// スケジュール (Schedule): the settings and the scheduled articles.
new #[Title('スケジュール')] class extends PagedList {
    /** UI: 平日の公開本数 */
    public int $articlesPerWeekday = 5;

    /** UI: 対象期間（日） */
    public int $periodDays = 7;

    /** UI 合格点: the quality score an article needs to be scheduled. */
    public int $passMark = 80;

    /** UI: 公開時刻 — local times, comma-separated */
    public string $publicationTimes = '';

    // Load the settings.
    public function mount(): void
    {
        $setting = ScheduleSetting::current();
        $this->articlesPerWeekday = $setting->articles_per_weekday;
        $this->periodDays = $setting->period_days;
        $this->passMark = $setting->pass_mark;
        $this->publicationTimes = implode(', ', (array) $setting->publication_times);
    }

    // Validate and save the settings.
    public function saveSettings(): void
    {
        $times = $this->parsedTimes();
        $this->validate([
            'articlesPerWeekday' => ['required', 'integer', 'min:1', 'max:'.max(1, count($times))],
            'periodDays' => ['required', 'integer', 'min:1', 'max:365'],
            'passMark' => ['required', 'integer', 'min:0', 'max:100'],
            'publicationTimes' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($times): void {
                if ($times === [] || count($times) !== count(array_filter($times, fn (string $time): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1))) {
                    $fail(__('Write the times as HH:MM, separated by commas.'));
                }
            }],
        ]);

        (ScheduleSetting::query()->first() ?? new ScheduleSetting)->fill(['articles_per_weekday' => $this->articlesPerWeekday, 'period_days' => $this->periodDays, 'publication_times' => $times, 'pass_mark' => $this->passMark])->save();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** @return list<string> the times, trimmed and sorted */
    private function parsedTimes(): array
    {
        $times = array_values(array_filter(array_map(trim(...), explode(',', str_replace('、', ',', $this->publicationTimes))), fn (string $time): bool => $time !== ''));
        sort($times);

        return $times;
    }

    // Give the waiting articles the free slots of the coming weekdays.
    public function schedule(ScheduleArticles $schedule): void
    {
        $count = $schedule(CarbonImmutable::now());
        unset($this->articles, $this->waiting);

        Flux::toast(variant: 'success', text: __(':count articles scheduled.', ['count' => $count]));
    }

    // Clear the unpublished slots and schedule again.
    public function reschedule(ScheduleArticles $schedule): void
    {
        ScheduleArticles::clear();
        $this->schedule($schedule);
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Article> the scheduled originals, soonest first */
    #[Computed]
    public function articles()
    {
        return Article::query()->originals()->whereNotNull('scheduled_at')->with('material.document.source', 'qualityCheck', 'translations')->orderBy('scheduled_at')->orderBy('id')->paginate($this->rowsPerPage());
    }

    /** Checked, unpublished originals at or above the pass mark without a slot. */
    #[Computed]
    public function waiting(): int
    {
        $passMark = ScheduleSetting::current()->pass_mark;

        return Article::query()->originals()->where('status', 'written')->whereNull('published_at')->whereNull('scheduled_at')
            ->whereRelation('qualityCheck', fn ($check) => $check->where('status', 'checked')->where('score', '>=', $passMark))->count();
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Schedule') }}</flux:heading>

    <form wire:submit="saveSettings" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Settings') }}</flux:heading>
        <flux:text>{{ __('Checked, unpublished articles whose primary source was published within the period are given the slots of the coming weekdays, best quality first. Every language version goes out at the same local date and time, each in its own zone:') }} {{ implode(' / ', array_map(fn ($language) => $language->label().' '.$language->timezone(), \App\Enums\Language::cases())) }}</flux:text>
        <div class="grid gap-3 sm:grid-cols-4">
            <flux:input type="number" wire:model="articlesPerWeekday" :label="__('Articles per weekday')" :description="__('Also how many articles are written a day')" min="1" />
            <flux:input type="number" wire:model="periodDays" :label="__('Period (days)')" min="1" />
            <flux:input type="number" wire:model="passMark" :label="__('Pass mark')" :description="__('Quality score needed to be scheduled')" min="0" max="100" />
            <flux:input wire:model="publicationTimes" :label="__('Publication times')" placeholder="07:00, 09:00, 12:00, 15:00, 18:00" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="schedule" icon="calendar-days">{{ __('Make the schedule') }}</flux:button>
            <flux:button type="button" wire:click="reschedule" icon="arrow-path" wire:confirm="{{ __('Take every unpublished article off the schedule and make it again?') }}">{{ __('Make the schedule again') }}</flux:button>
            <flux:text size="sm" class="text-neutral-500">{{ __('Waiting for a slot: :count', ['count' => $this->waiting]) }}</flux:text>
        </div>
    </form>

    <x-pages::table :columns="[__('Scheduled at (local time)'), __('Headline'), __('Quality'), __('Languages')]" :empty="$this->articles->isEmpty()">
        @foreach ($this->articles as $article)
            <tr>
                <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $article->scheduledLocalDisplay() }}</td>
                <td class="px-3 py-2"><x-pages::article-headline :article="$article" /></td>
                <td class="whitespace-nowrap px-3 py-2 tabular-nums" title="{{ $article->qualityCheck?->reason }}">{{ $article->qualityCheck?->score ?? '—' }}</td>
                {{-- Language versions; the tooltip gives the time in Japan. --}}
                <td class="px-3 py-2 text-neutral-500">
                    @foreach ($article->translations->where('status', '!=', 'failed')->prepend($article) as $version)
                        <span title="{{ $version->scheduled_at === null ? __('Not scheduled.') : __('Japan time').' '.$version->scheduled_at->setTimezone('Asia/Tokyo')->format('Y-m-d H:i') }}" @class(['text-amber-600' => $version->scheduled_at === null])>{{ $version->languageName() }}</span>@if (! $loop->last) / @endif
                    @endforeach
                </td>
            </tr>
        @endforeach
    </x-pages::table>

    <x-pages::pagination :paginator="$this->articles" />
</section>
