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

    public string $article = '';

    public function mount(): void
    {
        foreach (self::LAYERS as $layer) {
            $this->{$layer} = EditorialPolicy::bodyFor($layer);
        }
    }

    public function save(): void
    {
        foreach (self::LAYERS as $layer) {
            EditorialPolicy::query()->updateOrCreate(['layer' => $layer], ['body' => $this->{$layer}]);
        }

        Flux::toast(variant: 'success', text: __('Saved.'));
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl">{{ __('Editorial policy') }}</flux:heading>
    <flux:text>{{ __('Each layer is the prompt of its stage. The structuring layer is used when materials are extracted from documents: list the items as "- item: what to put there", one per line; the agent must fill every item. The article generation layer is used when articles are generated from materials: format, style, length, structure and quality criteria.') }}</flux:text>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:heading>{{ __('Selection') }}</flux:heading>
            <flux:text>{{ __('The title filter is set on the Sources screen, the content filtering on the Documents screen.') }} <a href="{{ route('sources.index') }}" class="underline" wire:navigate>{{ __('Sources') }}</a> / <a href="{{ route('documents.index') }}" class="underline" wire:navigate>{{ __('Documents') }}</a></flux:text>
        </div>
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:textarea wire:model="structuring" :label="__('Structuring')" rows="14" />
        </div>
        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:textarea wire:model="article" :label="__('Article generation')" rows="10" />
        </div>
        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
