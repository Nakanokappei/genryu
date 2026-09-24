<?php

use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 記事 (Articles) of 編成 (Production): the articles on their way out and those already out, one above the other. An article moves 公開日時未定 → 画像作成中 → スケジュール済み → 公開済み (Article::publicationStatus); nothing is moved by hand here.
new #[Title('記事')] class extends Component {
    /**
     * The articles on their way out: every checked original not yet
     * published, those with a time soonest first, then those waiting for one.
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
     * The articles already out, latest first.
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
                                {{ $article->scheduledLocal()?->locale(app()->getLocale())->isoFormat('YYYY-MM-DD（ddd） HH:mm') ?? '—' }}
                            @endif
                        </td>
                        <td class="px-3 py-2"><x-pages::favicon :source="$article->material?->document->source" /> <a href="{{ route('editorial.articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayHeadline() }}</a></td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums" title="{{ $article->qualityCheck?->reason }}">{{ $article->qualityCheck?->score ?? '—' }}</td>
                        {{-- The language versions that go out; the original is struck through when its language does not publish it (言語設定). --}}
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
