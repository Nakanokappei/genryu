{{-- A paged list's foot: rows per page, the count shown, and the page links. --}}
@props(['paginator'])

<div class="flex flex-wrap items-center gap-3">
    <flux:select wire:model.live="rowsPerPage" size="sm" class="w-24!">
        @foreach (\App\Livewire\PagedList::ROWS_PER_PAGE as $rows)
            <flux:select.option value="{{ $rows }}">{{ $rows }}</flux:select.option>
        @endforeach
    </flux:select>
    <flux:text size="sm" class="whitespace-nowrap">
        {{ __('rows per page') }}
        @if ($paginator->total() > 0)
            （{{ __('Showing') }} {{ $paginator->firstItem() }} {{ __('to') }} {{ $paginator->lastItem() }} {{ __('of') }} {{ $paginator->total() }} {{ __('results') }}）
        @endif
    </flux:text>
    {{-- Flux's pagination needs a width of its own; its own count is hidden. --}}
    <flux:pagination :paginator="$paginator" class="grow basis-80 border-t-0! pt-0! [&>div:first-child]:hidden" />
</div>
