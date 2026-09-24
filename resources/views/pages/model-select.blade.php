{{-- A select of models: name, id and description by default; `detail` short shows the name and id, described the name and description, name the name only; `enabled` lists the ids that may be chosen. --}}
@props(['models' => \App\Models\EditorialPolicy::TEXT_MODELS, 'detail' => 'full', 'enabled' => null])

<flux:select {{ $attributes }}>
    @foreach ($models as $id => $model)
        <flux:select.option value="{{ $id }}" :disabled="$enabled !== null && ! in_array($id, $enabled, true)">{{ match ($detail) { 'name' => $model['name'], 'short' => $model['name'].'（'.$id.'）', 'described' => $model['name'].' — '.__($model['description']), default => $model['name'].'（'.$id.'）— '.__($model['description']) } }}</flux:select.option>
    @endforeach
</flux:select>
