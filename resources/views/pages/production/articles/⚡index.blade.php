<?php

use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 記事 (Articles) of 編成 (Production): the originals scheduled and published.
new #[Title('記事')] class extends Component {
    /**
     * Checked, unpublished originals, soonest first, unscheduled last.
     *
     * @return Collection<int, Article>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        return $this->originals()->whereNull('published_at')->whereRelation('qualityCheck', 'status', 'checked')
            ->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->orderBy('id')->get();
    }

    /**
     * Published originals, latest first.
     *
     * @return Collection<int, Article>
     */
    #[Computed]
    public function published(): Collection
    {
        return $this->originals()->whereNotNull('published_at')->orderByDesc('published_at')->orderByDesc('id')->get();
    }

    /** @return Builder<Article> the originals, with what the rows show */
    private function originals(): Builder
    {
        return Article::query()->originals()->with('material.document.source', 'qualityCheck', 'translations');
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Articles') }}</flux:heading>

    @foreach (['upcoming' => __('Scheduled for publication'), 'published' => __('Published')] as $list => $heading)
        <div class="space-y-3">
            <flux:heading size="lg">{{ $heading }}（{{ $this->{$list}->count() }}）</flux:heading>
            <x-pages::table :columns="[__('Status'), $list === 'published' ? __('Published at') : __('Scheduled at (local time)'), __('Headline'), __('Quality'), __('Languages')]" :empty="$this->{$list}->isEmpty()">
                @foreach ($this->{$list} as $article)
                    <tr wire:key="{{ $list }}-{{ $article->id }}">
                        <td class="whitespace-nowrap px-3 py-2"><x-pages::status :status="$article->publicationStatus()" /></td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                            @if ($list === 'published')
                                {{ $article->published_at->display() }}
                            @else
                                {{ $article->scheduledLocalDisplay() ?? '—' }}
                            @endif
                        </td>
                        <td class="px-3 py-2"><x-pages::article-headline :article="$article" /></td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums" title="{{ $article->qualityCheck?->reason }}">{{ $article->qualityCheck?->score ?? '—' }}</td>
                        {{-- Language versions; one not published (言語設定) is struck through. --}}
                        <td class="px-3 py-2 text-neutral-500">
                            @foreach ($article->translations->where('status', '!=', 'failed')->prepend($article) as $version)
                                <span @class(['line-through' => ! $version->isPublishable()]) title="{{ $version->isPublishable() ? '' : __('Not published in this language.') }}">{{ $version->languageName() }}</span>@if (! $loop->last) / @endif
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </x-pages::table>
        </div>
    @endforeach
</section>
