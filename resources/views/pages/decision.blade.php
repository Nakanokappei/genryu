{{-- A document's standing decision as a badge: 人の判定, else the latest screening's, else a dash. --}}
@props(['document'])

@php $screening = $document->latestScreening; @endphp

@if ($document->human_decision !== null)
    <flux:tooltip :content="__('Human decision').'：'.($document->human_reason ?? '—')">
        <flux:badge size="sm" icon="user" :color="$document->human_decision === 'adopt' ? 'green' : 'red'">{{ __($document->human_decision) }}</flux:badge>
    </flux:tooltip>
@elseif ($screening === null)
    —
@elseif ($screening->status === 'screened')
    <flux:tooltip :content="$screening->reason_class.' — '.$screening->reason">
        <flux:badge size="sm" :color="match ($screening->decision) { 'adopt' => 'green', 'reject' => 'red', default => 'amber' }">{{ __($screening->decision) }}</flux:badge>
    </flux:tooltip>
@elseif ($screening->status === 'failed')
    <flux:tooltip :content="$screening->status_message ?? ''"><x-pages::status status="failed" /></flux:tooltip>
@else
    <x-pages::status :status="$screening->status" />
@endif
