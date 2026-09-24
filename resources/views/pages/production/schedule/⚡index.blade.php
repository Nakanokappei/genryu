<?php

use App\Actions\ScheduleArticles;
use App\Livewire\PagedList;
use App\Models\Article;
use App\Models\ScheduleSetting;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// スケジュール (Schedule), the second screen of 編成 (Production): the settings the publication times are set by — how many articles go out on a weekday, how many days a source stays fresh, the local times of day — above the articles with their slots, every language version at that local time in its own zone. The schedule is made by ScheduleArticles; nothing is timed by hand here.
new #[Title('スケジュール')] class extends PagedList {
    /** UI 平日の公開本数 */
    public int $articlesPerWeekday = 5;

    /** UI 対象期間（日） */
    public int $periodDays = 7;

    /** UI 公開時刻: local times of day, separated by commas. */
    public string $publicationTimes = '';

    public function mount(): void
    {
        $setting = ScheduleSetting::current();
        $this->articlesPerWeekday = $setting->articles_per_weekday;
        $this->periodDays = $setting->period_days;
        $this->publicationTimes = implode(', ', (array) $setting->publication_times);
    }

    public function saveSettings(): void
    {
        $times = $this->parsedTimes();
        $this->validate([
            'articlesPerWeekday' => ['required', 'integer', 'min:1', 'max:'.max(1, count($times))],
            'periodDays' => ['required', 'integer', 'min:1', 'max:365'],
            'publicationTimes' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($times): void {
                if ($times === [] || count($times) !== count(array_filter($times, fn (string $time): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1))) {
                    $fail(__('Write the times as HH:MM, separated by commas.'));
                }
            }],
        ]);

        (ScheduleSetting::query()->first() ?? new ScheduleSetting)->fill(['articles_per_weekday' => $this->articlesPerWeekday, 'period_days' => $this->periodDays, 'publication_times' => $times])->save();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** @return list<string> the times as written, trimmed, in order */
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

    // Take the unpublished articles off the schedule and make it again, as after the settings changed.
    public function reschedule(ScheduleArticles $schedule): void
    {
        ScheduleArticles::clear();
        $this->schedule($schedule);
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Article> */
    #[Computed]
    public function articles()
    {
        // The scheduled originals, soonest first; each carries its translations and their times.
        return Article::query()->originals()->whereNotNull('scheduled_at')->with('material.document.source', 'qualityCheck', 'translations')->orderBy('scheduled_at')->orderBy('id')->paginate($this->rowsPerPage());
    }

    /** How many checked, unpublished articles have no slot yet, fresh or not. */
    #[Computed]
    public function waiting(): int
    {
        return Article::query()->originals()->where('status', 'written')->whereNull('published_at')->whereNull('scheduled_at')->whereRelation('qualityCheck', 'status', 'checked')->count();
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Schedule') }}</flux:heading>

    {{-- The settings sit with the schedule because they are what make it. --}}
    <form wire:submit="saveSettings" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Settings') }}</flux:heading>
        <flux:text>{{ __('Checked, unpublished articles whose primary source was published within the period are given the slots of the coming weekdays, best quality first. Every language version goes out at the same local date and time, each in its own zone:') }} {{ implode(' / ', array_map(fn ($language) => $language->label().' '.$language->timezone(), \App\Enums\Language::cases())) }}</flux:text>
        <div class="grid gap-3 sm:grid-cols-3">
            <flux:input type="number" wire:model="articlesPerWeekday" :label="__('Articles per weekday')" min="1" />
            <flux:input type="number" wire:model="periodDays" :label="__('Period (days)')" min="1" />
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
                <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $article->scheduledLocal()?->locale(app()->getLocale())->isoFormat('YYYY-MM-DD（ddd） HH:mm') }}</td>
                <td class="px-3 py-2"><x-pages::favicon :source="$article->material?->document->source" /> <a href="{{ route('editorial.articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayHeadline() }}</a></td>
                <td class="whitespace-nowrap px-3 py-2 tabular-nums" title="{{ $article->qualityCheck?->reason }}">{{ $article->qualityCheck?->score ?? '—' }}</td>
                {{-- Each language version with its own zone; hovering shows when that is in Japan. --}}
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
