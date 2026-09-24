{{-- A select of models; `detail` is full / short / described / name, `enabled` limits the choosable ids. --}}
@props(['models' => \App\Models\EditorialPolicy::TEXT_MODELS, 'detail' => 'full', 'enabled' => null])

<flux:select {{ $attributes }}>
    @foreach ($models as $id => $model)
        <flux:select.option value="{{ $id }}" :disabled="$enabled !== null && ! in_array($id, $enabled, true)">{{ match ($detail) { 'name' => $model['name'], 'short' => $model['name'].'（'.$id.'）', 'described' => $model['name'].' — '.__($model['description']), default => $model['name'].'（'.$id.'）— '.__($model['description']) } }}</flux:select.option>
    @endforeach
</flux:select>
