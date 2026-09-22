<?php

use App\Models\EditorialPolicy;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 編集方針 (Editorial policy): one body of text per layer; each stage reads its layer as its prompt.
new #[Title('編集方針')] class extends Component {
    // The selection layer is set on the 文書 screen and the structuring layer on the 素材情報 screen, each next to what it governs.
    public const LAYERS = ['article'];

    public string $article = '';

    /** The model the article is written with (UI: モデル), one of EditorialPolicy::MODELS. */
    public string $articleModel = EditorialPolicy::DEFAULT_MODEL;

    public function mount(): void
    {
        foreach (self::LAYERS as $layer) {
            $this->{$layer} = EditorialPolicy::bodyFor($layer);
        }

        $this->articleModel = EditorialPolicy::modelFor('article');
    }

    public function save(): void
    {
        $this->validate(['articleModel' => ['required', 'in:'.implode(',', array_keys(EditorialPolicy::MODELS))]]);

        // The layer runs on the model chosen for it (UI: 記事生成のモデル).
        foreach (self::LAYERS as $layer) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $this->{$layer}, 'model' => $this->{$layer.'Model'}]);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Editorial policy') }}</flux:heading>
    <flux:text>{{ __('Each layer is the prompt of its stage, and is set next to what it governs. The article generation layer is used when articles are generated from materials: format, style, length, structure and quality criteria.') }}</flux:text>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:heading>{{ __('Selection') }}</flux:heading>
            <flux:text>{{ __('The title filter is set on the Sources screen, the content filtering on the Documents screen.') }} <a href="{{ route('sources.index') }}" class="underline" wire:navigate>{{ __('Sources') }}</a> / <a href="{{ route('documents.index') }}" class="underline" wire:navigate>{{ __('Documents') }}</a></flux:text>
        </div>
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:heading>{{ __('Structuring') }}</flux:heading>
            <flux:text>{{ __('The structuring layer is set on the Materials screen, above what it makes.') }} <a href="{{ route('materials.index') }}" class="underline" wire:navigate>{{ __('Materials') }}</a></flux:text>
        </div>
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:textarea wire:model="article" :label="__('Article generation')" rows="10" />
            <flux:select wire:model="articleModel" :label="__('Model of the article generation')" class="max-w-xl">
                @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                    <flux:select.option value="{{ $id }}">{{ $model['name'] }}（{{ $id }}）— {{ __($model['description']) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
