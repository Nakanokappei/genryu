{{-- A plain list table shared by the workflow screens: headings, rows in the slot, an empty message. --}}
{{-- A column is a label, or ['label' => …, 'sort' => key] for a heading that sorts the list (the page's sortBy(key), with its current sort and direction). --}}
@props(['columns' => [], 'empty' => false, 'sort' => null, 'direction' => 'desc'])

<div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
    <table class="w-full text-left text-sm">
        <thead class="bg-neutral-50 text-neutral-500 dark:bg-neutral-900">
            <tr>
                @foreach ($columns as $column)
                    <th class="whitespace-nowrap px-3 py-2 font-medium">
                        @if (is_array($column))
                            <button type="button" wire:click="sortBy('{{ $column['sort'] }}')" class="inline-flex items-center gap-1 hover:text-neutral-800 dark:hover:text-neutral-200">
                                {{ $column['label'] }}
                                @if ($sort === $column['sort'])
                                    <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        @else
                            {{ $column }}
                        @endif
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
            @if ($empty)
                <tr>
                    <td colspan="{{ count($columns) }}" class="px-3 py-6 text-center text-neutral-500">{{ __('No records yet.') }}</td>
                </tr>
            @else
                {{ $slot }}
            @endif
        </tbody>
    </table>
</div>
