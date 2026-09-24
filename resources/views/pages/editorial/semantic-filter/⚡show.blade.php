<?php

use App\Actions\MeasureLikeness;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\SemanticFilterExample;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 意味フィルタ — らしい / らしくない: one side of the semantic filter on a screen of its own, its definitions (one per line) and the examples a person marked on that side. The embedding model and the threshold are on 文書.
new #[Title('意味フィルタ')] class extends Component {
    /** like (UI らしい) or unlike (UI らしくない), from the URL */
    public string $side = 'like';

    /** The definitions of this side, one per line. */
    public string $definitions = '';

    public function mount(string $side): void
    {
        abort_unless(array_key_exists($side, SemanticFilterExample::SIDES), 404);
        $this->side = $side;
        $this->definitions = EditorialPolicy::bodyFor(SemanticFilterExample::layerOf($side));
    }

    // Saved, every embedded document is measured again: only a new definition line calls the model.
    public function save(MeasureLikeness $measure): void
    {
        EditorialPolicy::query()->updateOrCreate(['layer' => SemanticFilterExample::layerOf($this->side)], ['body' => $this->definitions]);
        $result = $measure->again();

        Flux::toast(variant: 'success', duration: 8000, text: __('Saved. :measured documents measured again, :below below the threshold.', $result));
    }

    // Take a document off this side's examples; every embedded document is measured again without it.
    public function removeExample(int $exampleId, MeasureLikeness $measure): void
    {
        SemanticFilterExample::query()->whereKey($exampleId)->where('side', $this->side)->delete();
        unset($this->examples);
        $result = $measure->again();

        Flux::toast(variant: 'success', duration: 8000, text: __('Saved. :measured documents measured again, :below below the threshold.', $result));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SemanticFilterExample> this side's examples, newest first */
    #[Computed]
    public function examples()
    {
        return SemanticFilterExample::query()->where('side', $this->side)->with('document.source')->latest('id')->get();
    }
}; ?>

<section class="w-full space-y-6">
    <x-pages::detail-header :back="route('editorial.documents.index')" :back-label="__('Documents')" :title="__('Semantic filter').' — '.($side === 'like' ? __('Like this media') : __('Unlike this media'))" />

    <form wire:submit="save" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Definitions') }}</flux:heading>
        <flux:text>{{ $side === 'like'
            ? __('What this media is like, one definition per line. A document nearer to these than to the definitions and examples of the other side goes on to the screening.')
            : __('What this media is not like, one definition per line. A document nearer to these than to the definitions and examples of the other side stops at the semantic filter.') }}</flux:text>
        <flux:textarea wire:model="definitions" rows="12" />
        <flux:text size="sm" class="text-neutral-500">{{ __('Write each definition as a few concrete sentences: a broad word (measure, AI) pulls everything towards it. The embedding model and the threshold are on the Documents screen.') }}</flux:text>
        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>

    <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Examples') }}（{{ $this->examples->count() }}）</flux:heading>
        <flux:text>{{ __('Documents a person marked on this side, on the document\'s screen. Every document is measured against them as well as the definitions; an example is never measured against itself.') }}</flux:text>
        @forelse ($this->examples as $example)
            <div class="flex items-start gap-3 border-t border-neutral-200 pt-2 dark:border-neutral-700" wire:key="example-{{ $example->id }}">
                <div class="min-w-0 flex-1">
                    <x-pages::favicon :source="$example->document->source" /> <a href="{{ route('editorial.documents.show', $example->document) }}" class="underline" wire:navigate>{{ $example->document->title }}</a>
                    <div class="text-xs text-neutral-500">{{ $example->document->source->name }} / {{ __('Likeness') }} {{ $example->document->likeness !== null ? sprintf('%+.2f', $example->document->likeness) : '—' }}</div>
                </div>
                <flux:button size="sm" wire:click="removeExample({{ $example->id }})">{{ __('Not an example') }}</flux:button>
            </div>
        @empty
            <flux:text class="text-neutral-500">—</flux:text>
        @endforelse
    </div>
</section>
