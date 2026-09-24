<?php

use App\Jobs\MakeImage;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\ImageStyle;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 画像 (Images) of 編成 (Production): the image layer of the editorial policy (how the scene is chosen, and the models that write it and draw it), the style of each time band of the day, and the scheduled articles with their top images. An article is drawn in the style of the band its slot falls in; nothing is drawn by hand here.
new #[Title('画像')] class extends Component {
    /** The developer prompt of the scene writer, and the two models. */
    public string $image = '';

    public string $imageWriterModel = EditorialPolicy::DEFAULT_MODEL;

    public string $imageModel = EditorialPolicy::DEFAULT_IMAGE_MODEL;

    /** 時間帯ごとの絵柄: each band's name, start and style. @var array<string, array{name: string, starts_at: string, style: string}> */
    public array $bands = [];

    public function mount(): void
    {
        $this->image = EditorialPolicy::bodyFor('image');
        $this->imageWriterModel = EditorialPolicy::modelFor('image');
        $this->imageModel = EditorialPolicy::imageModel();
        $this->bands = ImageStyle::bands();
    }

    public function savePolicy(): void
    {
        $this->validate([
            'imageWriterModel' => EditorialPolicy::modelRule(),
            'imageModel' => EditorialPolicy::modelRule(EditorialPolicy::IMAGE_MODELS),
            'bands.*.name' => ['required', 'string', 'max:64'],
            'bands.*.starts_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', 'distinct'],
            'bands.*.style' => ['required', 'string'],
        ]);

        EditorialPolicy::query()->updateOrCreate(['layer' => 'image'], ['body' => $this->image, 'model' => $this->imageWriterModel, 'image_model' => $this->imageModel]);

        foreach ($this->bands as $band => $settings) {
            ImageStyle::query()->updateOrCreate(['band' => $band], ['name' => $settings['name'], 'starts_at' => $settings['starts_at'], 'style' => trim($settings['style'])]);
        }

        $this->bands = ImageStyle::bands();
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /**
     * The scheduled originals not yet published, soonest first, with their latest drawing.
     *
     * @return Collection<int, Article>
     */
    #[Computed]
    public function articles(): Collection
    {
        return Article::query()->originals()->whereNotNull('scheduled_at')->whereNull('published_at')
            ->with('material.document.source', 'image')->orderBy('scheduled_at')->orderBy('id')->get();
    }

    // Draw every scheduled article that has no image for its time of day and none on the way.
    public function make(): void
    {
        $articles = $this->articles->filter(fn (Article $article): bool => $article->publicationStatus() === 'imaging' && $article->image?->status !== 'making');
        $articles->each(fn (Article $article) => MakeImage::queueFor($article));
        unset($this->articles);

        Flux::toast(variant: 'success', text: __(':count images queued.', ['count' => $articles->count()]));
    }

    // Draw one article again, as after its image did not come out well.
    public function remake(int $articleId): void
    {
        MakeImage::queueFor($this->articles->firstOrFail('id', $articleId));
        unset($this->articles);

        Flux::toast(variant: 'success', text: __(':count images queued.', ['count' => 1]));
    }
}; ?>

<section class="w-full space-y-6" @if ($this->articles->contains(fn ($article) => $article->image?->status === 'making')) wire:poll.5s @endif>
    <flux:heading size="xl">{{ __('Images') }}</flux:heading>

    <form wire:submit="savePolicy" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Editorial policy') }}</flux:heading>
        <flux:text>{{ __('A top image is made in two steps: an LLM chooses the scene from the article, fitting the theme of the hour it goes out at, and the image model draws it in the style of that hour. One image serves every language, which go out at the same local time.') }}</flux:text>
        <flux:textarea wire:model="image" :label="__('Developer prompt (editable)')" rows="10" class="font-mono text-xs" />
        <x-pages::fixed-prompts :instruction="\App\Actions\ProposeScene::INSTRUCTIONS" :input="[__('The article as written'), __('The material, as JSON'), __('The style of the hour')]" />
        <div class="grid gap-3 sm:grid-cols-2">
            <x-pages::model-select wire:model="imageWriterModel" :label="__('Model of the scene')" detail="short" />
            <x-pages::model-select wire:model="imageModel" :label="__('Image model')" :models="\App\Models\EditorialPolicy::IMAGE_MODELS" />
        </div>

        {{-- 時間帯ごとの絵柄: each band runs from its start to the next one's; the last runs past midnight to the first. --}}
        <div class="space-y-2">
            <flux:label>{{ __('Style by time of day') }}</flux:label>
            @foreach ($bands as $band => $settings)
                <div class="grid gap-2 rounded-lg border border-neutral-200 p-3 sm:grid-cols-[10rem_6rem_1fr] dark:border-neutral-700" wire:key="band-{{ $band }}">
                    <flux:input wire:model="bands.{{ $band }}.name" :aria-label="__('Name')" size="sm" />
                    <flux:input wire:model="bands.{{ $band }}.starts_at" :aria-label="__('Starts at')" size="sm" class="tabular-nums" />
                    <flux:textarea wire:model="bands.{{ $band }}.style" :aria-label="__('Style')" rows="3" class="font-mono text-xs" />
                </div>
            @endforeach
            <flux:text size="sm" class="text-neutral-500">{{ __('Always added to the image prompt:') }} {{ \App\Actions\DrawImage::NEVER }}</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button type="button" wire:click="make" icon="photo" wire:confirm="{{ __('Draw every scheduled article that has no image for its time of day? Each one is two calls, one of them to the image model.') }}">{{ __('Make images') }}</flux:button>
        </div>
    </form>

    <x-pages::table :columns="[__('Top image'), __('Scheduled at (local time)'), __('Time band'), __('Title'), __('Status'), '']" :empty="$this->articles->isEmpty()">
        @foreach ($this->articles as $article)
            @php $image = $article->image; $band = \App\Models\ImageStyle::bandFor((string) $article->scheduledLocal()?->format('H:i')); @endphp
            <tr wire:key="article-{{ $article->id }}">
                <td class="px-3 py-2">
                    @if ($image?->status === 'made')
                        <a href="{{ route('production.images.file', $image) }}" target="_blank" title="{{ $image->scene }}"><img src="{{ route('production.images.file', $image) }}" alt="" loading="lazy" class="aspect-video w-40 rounded-md border border-neutral-200 object-cover dark:border-neutral-700"></a>
                    @else
                        <div class="aspect-video w-40 rounded-md border border-dashed border-neutral-300 dark:border-neutral-600"></div>
                    @endif
                </td>
                <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $article->scheduledLocal()?->locale(app()->getLocale())->isoFormat('YYYY-MM-DD（ddd） HH:mm') }}</td>
                <td class="whitespace-nowrap px-3 py-2">{{ $bands[$band]['name'] ?? $band }}</td>
                <td class="px-3 py-2"><x-pages::favicon :source="$article->material?->document->source" /> <a href="{{ route('editorial.articles.show', $article) }}" class="underline" wire:navigate>{{ $article->displayTitle() }}</a></td>
                <td class="whitespace-nowrap px-3 py-2">
                    <x-pages::status :status="$article->publicationStatus()" />
                    @if ($image !== null)
                        <span title="{{ $image->status_message }}"><x-pages::status :status="$image->status" /></span>
                    @endif
                </td>
                <td class="whitespace-nowrap px-3 py-2">
                    @if ($image?->status !== 'making')
                        <flux:button size="xs" wire:click="remake({{ $article->id }})" icon="arrow-path">{{ __('Draw again') }}</flux:button>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-pages::table>
</section>
