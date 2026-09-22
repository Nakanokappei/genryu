<x-layouts::app :title="__('Dashboard')">
    {{-- One card per stage, in flow order, with its record count. --}}
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
        <flux:text>{{ __('Sources → Updates → Materials → Articles') }}</flux:text>

        <div class="grid gap-4 md:grid-cols-4">
            @foreach ([
                ['Sources', \App\Models\Source::count(), route('sources.index')],
                ['Updates', \App\Models\UpdateEntry::count(), route('updates.index')],
                ['Materials', \App\Models\Material::count(), route('materials.index')],
                ['Articles', \App\Models\Article::count(), route('articles.index')],
            ] as [$label, $count, $href])
                <a href="{{ $href }}" wire:navigate class="rounded-xl border border-neutral-200 p-4 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-900">
                    <flux:text>{{ __($label) }}</flux:text>
                    <flux:heading size="xl">{{ $count }}</flux:heading>
                </a>
            @endforeach
        </div>
    </div>
</x-layouts::app>
