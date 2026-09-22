{{-- The foot of a paged list: the rows-per-page choice (App\Livewire\PagedList) and the page links. --}}
@props(['paginator'])

<div class="flex flex-wrap items-center gap-3">
    <flux:select wire:model.live="rowsPerPage" size="sm" class="w-24!">
        @foreach (\App\Livewire\PagedList::ROWS_PER_PAGE as $rows)
            <flux:select.option value="{{ $rows }}">{{ $rows }}</flux:select.option>
        @endforeach
    </flux:select>
    <flux:text size="sm">{{ __('rows per page') }}</flux:text>
    <flux:pagination :paginator="$paginator" class="ms-auto border-t-0 pt-0" />
</div>
