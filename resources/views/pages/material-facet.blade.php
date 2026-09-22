{{-- One facet of a material (UI: a heading of the 素材情報): its status and the claims it names, each with its confidence and the quotes it rests on. --}}
@props(['label', 'facet', 'claims', 'spans'])

@php $ids = $facet['claim_ids'] ?? []; $status = $facet['status'] ?? 'unknown'; @endphp

<div class="space-y-1">
    <div class="flex items-center gap-2">
        <flux:subheading>{{ $label }}</flux:subheading>
        @if ($status !== 'supported')
            <flux:badge size="sm" color="zinc">{{ __($status) }}</flux:badge>
        @endif
    </div>
    @foreach ($ids as $id)
        <x-pages::material-claim :claim="$claims[$id] ?? null" :id="$id" :claims="$claims" :spans="$spans" />
    @endforeach
</div>
