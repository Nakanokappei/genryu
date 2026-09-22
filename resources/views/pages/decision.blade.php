{{-- The decision that stands for a document as a badge: a person's verdict (採用 / 不採用 with a person mark, the reason as tooltip) before the latest screening's (採用 / 不採用 / 要確認, the reason class and the model's reason as tooltip), or where the run stands (判定中 / 失敗 with its message); a dash when nothing has decided. --}}
@props(['document'])

@php $screening = $document->screening; @endphp

@if ($document->human_decision !== null)
    <flux:tooltip :content="__('Human decision').'：'.($document->human_reason ?? '—')">
        <flux:badge size="sm" icon="user" :color="$document->human_decision === 'adopt' ? 'green' : 'red'">{{ __($document->human_decision) }}</flux:badge>
    </flux:tooltip>
@elseif ($screening === null)
    —
@elseif ($screening->status === 'screened')
    <flux:tooltip :content="$screening->primary_reason.' — '.$screening->reason">
        <flux:badge size="sm" :color="match ($screening->decision) { 'adopt' => 'green', 'reject' => 'red', default => 'amber' }">{{ __($screening->decision) }}</flux:badge>
    </flux:tooltip>
@elseif ($screening->status === 'failed')
    <flux:tooltip :content="$screening->status_message ?? ''"><x-pages::status status="failed" /></flux:tooltip>
@else
    <x-pages::status :status="$screening->status" />
@endif
