{{-- Label / value pairs of a detail screen; empty values show a dash. --}}
@props(['fields' => []])

<dl class="grid gap-x-6 gap-y-2 rounded-xl border border-neutral-200 p-4 text-sm md:grid-cols-[max-content_1fr] dark:border-neutral-700">
    @foreach ($fields as $label => $value)
        <dt class="text-neutral-500">{{ $label }}</dt>
        <dd class="break-all">{{ $value !== null && $value !== '' ? $value : '—' }}</dd>
    @endforeach
</dl>
