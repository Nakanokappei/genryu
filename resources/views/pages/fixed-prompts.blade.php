{{-- The two messages that follow the editable prompt on every run: the fixed instruction, then the input of the run. Shown so that nobody is tempted to write {本文} or {素材情報} into the prompt above — what changes per run is a message of its own, not a placeholder. The input is named, not printed: one box per value that is put in at run time. --}}
@props(['instruction' => null, 'input'])

@if ($instruction !== null)
    <div class="space-y-2">
        <flux:label>{{ __('Developer prompt (fixed)') }}</flux:label>
        <textarea readonly rows="4" class="block w-full resize-y rounded-lg border border-neutral-200 bg-neutral-50 p-3 font-mono text-xs whitespace-pre-wrap text-neutral-600 dark:border-white/10 dark:bg-white/5 dark:text-neutral-400">{{ $instruction }}</textarea>
    </div>
@endif

<div class="space-y-2">
    <flux:label>{{ __('User prompt (fixed)') }}</flux:label>
    <div class="flex flex-wrap gap-2">
        @foreach ($input as $object)
            <span class="rounded-md border border-sky-300 bg-sky-100/70 px-2 py-1 text-xs text-sky-900 dark:border-sky-400/40 dark:bg-sky-400/10 dark:text-sky-200">{{ $object }}</span>
        @endforeach
    </div>
    <flux:text size="sm" class="text-neutral-500">{{ __('The developer prompt needs no placeholders: the values in the boxes are put in at run time and sent after it.') }}</flux:text>
</div>
