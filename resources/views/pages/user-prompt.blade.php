{{-- What the model is sent after the developer prompt above: the fixed instruction, then the text of this run. Shown so that nobody is tempted to write {本文} or {素材情報} into the prompt — what changes per run is a message of its own, not a placeholder. --}}
@props(['messages'])

<div class="space-y-2">
    <flux:label>{{ __('User prompt (sent after the developer prompt, not editable)') }}</flux:label>
    <textarea readonly rows="6" class="block w-full resize-y rounded-lg border border-neutral-200 bg-neutral-50 p-3 font-mono text-xs whitespace-pre-wrap text-neutral-600 dark:border-white/10 dark:bg-white/5 dark:text-neutral-400">{{ collect($messages)->map(fn (array $message): string => '['.$message['role'].'] '.$message['text'])->implode("\n\n") }}</textarea>
    <flux:text size="sm" class="text-neutral-500">{{ __('The developer prompt needs no placeholders: what changes with each run is sent after it as its own message.') }}</flux:text>
</div>
