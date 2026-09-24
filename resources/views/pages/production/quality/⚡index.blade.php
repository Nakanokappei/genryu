<?php

use App\Jobs\CheckQuality;
use App\Livewire\PagedList;
use App\Models\Article;
use App\Models\EditorialPolicy;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

// 品質チェック (Quality check): the quality layer and the originals with their latest check.
new #[Title('品質チェック')] class extends PagedList {
    /** The quality layer's prompt (rubric included) and model. */
    public string $quality = '';

    public string $qualityModel = EditorialPolicy::DEFAULT_MODEL;

    // Load the quality layer.
    public function mount(): void
    {
        $this->quality = EditorialPolicy::bodyFor('quality');
        $this->qualityModel = EditorialPolicy::modelFor('quality');
    }

    // Save the quality prompt and model.
    public function savePolicy(): void
    {
        $this->validate(['qualityModel' => EditorialPolicy::modelRule()]);
        EditorialPolicy::query()->updateOrCreate(['layer' => 'quality'], ['body' => $this->quality, 'model' => $this->qualityModel]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Article> the originals with a body */
    #[Computed]
    public function articles()
    {
        return Article::query()->originals()->whereNotNull('body')->with('material.document.source', 'qualityCheck')->latest()->latest('id')->paginate($this->rowsPerPage());
    }

    // Queue a check for the unchecked or failed originals, or for all.
    public function check(bool $all = false): void
    {
        $articles = Article::query()->originals()->whereNotNull('body')->where('status', 'written')
            ->unless($all, fn ($query) => $query->where(fn ($query) => $query->whereDoesntHave('qualityCheck')->orWhereRelation('qualityCheck', 'status', 'failed')))->get();
        $articles->each(fn (Article $article) => CheckQuality::queueFor($article));
        unset($this->articles);

        Flux::toast(variant: 'success', text: __(':count quality checks queued.', ['count' => $articles->count()]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->articles->contains(fn ($article) => $article->qualityCheck?->status === 'checking')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Quality check') }}</flux:heading>

    <form wire:submit="savePolicy" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }}</flux:heading>
        <flux:text>{{ __('The developer prompt and the model of the quality check: every written article is scored out of 100 against the media\'s standards and the rubric written here, with the reason the points were lost. The rubric lives in this prompt, so changing it here changes how articles are scored; check them all again after a change.') }}</flux:text>
        <flux:textarea wire:model="quality" :label="__('Developer prompt (editable)')" rows="12" class="font-mono text-xs" />
        <x-pages::fixed-prompts :instruction="\App\Actions\ScoreQuality::INSTRUCTIONS" :input="[__('The headline'), __('The article as written'), __('The material, as JSON')]" />
        <x-pages::model-select wire:model="qualityModel" :label="__('Model of the quality check')" class="max-w-xl" />

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="check" icon="check-badge" wire:confirm="{{ __('Check every article that has not been checked, or whose check failed? Each one is one call to the model.') }}">{{ __('Check unchecked articles') }}</flux:button>
            <flux:button type="button" wire:click="check(true)" icon="arrow-path" wire:confirm="{{ __('Check every article again? Each one is one call to the model.') }}">{{ __('Check all articles again') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Headline'), __('Status'), __('Quality'), __('Checked at')]" :empty="$this->articles->isEmpty()">
        @foreach ($this->articles as $article)
            @php $check = $article->qualityCheck; @endphp
            <tr>
                <td class="px-3 py-2"><x-pages::article-headline :article="$article" /></td>
                <td class="whitespace-nowrap px-3 py-2">
                    @if ($check === null)
                        <span class="text-neutral-500">—</span>
                    @else
                        <span title="{{ $check->status === 'failed' ? $check->status_message : '' }}"><x-pages::status :status="$check->status" /></span>
                    @endif
                </td>
                {{-- The score; the reason as tooltip. --}}
                <td class="whitespace-nowrap px-3 py-2 tabular-nums" title="{{ $check?->reason }}">{{ $check?->score ?? '—' }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-neutral-500">{{ $check?->status === 'checked' ? $check->updated_at->display() : '—' }}</td>
            </tr>
        @endforeach
    </x-pages::table>

    <x-pages::pagination :paginator="$this->articles" />
</section>
