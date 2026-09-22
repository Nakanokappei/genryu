{{-- The two messages that follow the editable prompt on every run: the fixed instruction, then the text of the run itself. Shown so that nobody is tempted to write {本文} or {素材情報} into the prompt above — what changes per run is a message of its own, not a placeholder. --}}
@props(['instruction' => null, 'input'])

@php $box = 'block w-full resize-y rounded-lg border border-neutral-200 bg-neutral-50 p-3 font-mono text-xs whitespace-pre-wrap text-neutral-600 dark:border-white/10 dark:bg-white/5 dark:text-neutral-400'; @endphp

@if ($instruction !== null)
    <div class="space-y-2">
        <flux:label>{{ __('Developer prompt (fixed)') }}</flux:label>
        <textarea readonly rows="4" class="{{ $box }}">{{ $instruction }}</textarea>
    </div>
@endif

<div class="space-y-2">
    <flux:label>{{ __('User prompt (fixed)') }}</flux:label>
    <textarea readonly rows="3" class="{{ $box }}">{{ $input }}</textarea>
    <flux:text size="sm" class="text-neutral-500">{{ __('The developer prompt needs no placeholders: what changes with each run is sent after it as its own message.') }}</flux:text>
</div>
