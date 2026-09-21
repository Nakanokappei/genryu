{{-- A plain list table shared by the workflow screens: headings, rows in the slot, an empty message. --}}
@props(['columns' => [], 'empty' => false])

<div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
    <table class="w-full text-left text-sm">
        <thead class="bg-neutral-50 text-neutral-500 dark:bg-neutral-900">
            <tr>
                @foreach ($columns as $column)
                    <th class="px-3 py-2 font-medium">{{ $column }}</th>
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
