<?php

use App\Models\EditorialPolicy;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

// 編集方針 (Editorial policy): one body of text per layer; each stage reads its layer as its prompt.
new #[Title('編集方針')] class extends Component {
    // The selection layer is set on the 文書 screen, next to what it screens.
    public const LAYERS = ['structuring', 'article'];

    public string $structuring = '';

    /** The model the material is built with (UI: モデル), one of EditorialPolicy::MODELS. */
    public string $structuringModel = EditorialPolicy::DEFAULT_MODEL;

    public string $article = '';

    public function mount(): void
    {
        foreach (self::LAYERS as $layer) {
            $this->{$layer} = EditorialPolicy::bodyFor($layer);
        }

        $this->structuringModel = EditorialPolicy::modelFor('structuring');
    }

    public function save(): void
    {
        $this->validate(['structuringModel' => ['required', 'in:'.implode(',', array_keys(EditorialPolicy::MODELS))]]);

        foreach (self::LAYERS as $layer) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $this->{$layer}, ...($layer === 'structuring' ? ['model' => $this->structuringModel] : [])]);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Editorial policy') }}</flux:heading>
    <flux:text>{{ __('Each layer is the prompt of its stage. The structuring layer is the developer prompt of the analyst that builds a material from an adopted document: the evidence quoted with its lines, the claims and inferences, the technology transition, the engineering and the possible angles; a changed prompt is a new version, pinned by every material. The article generation layer is used when articles are generated from materials: format, style, length, structure and quality criteria.') }}</flux:text>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:heading>{{ __('Selection') }}</flux:heading>
            <flux:text>{{ __('The title filter is set on the Sources screen, the content filtering on the Documents screen.') }} <a href="{{ route('sources.index') }}" class="underline" wire:navigate>{{ __('Sources') }}</a> / <a href="{{ route('documents.index') }}" class="underline" wire:navigate>{{ __('Documents') }}</a></flux:text>
        </div>
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:textarea wire:model="structuring" :label="__('Structuring')" rows="24" class="font-mono text-xs" />
            <flux:select wire:model="structuringModel" :label="__('Model of the structuring')" class="max-w-xl">
                @foreach (\App\Models\EditorialPolicy::MODELS as $id => $model)
                    <flux:select.option value="{{ $id }}">{{ $model['name'] }}（{{ $id }}）— {{ __($model['description']) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:textarea wire:model="article" :label="__('Article generation')" rows="10" />
        </div>
        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
