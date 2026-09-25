{{-- An article's top image, or a placeholder block with the source's name. --}}
@if (\App\Http\Controllers\MediaController::imagePathOf($article))
    <img src="{{ route('media.image', $article) }}" alt="" loading="lazy" class="{{ $class }}">
@else
    <div class="{{ $class }} flex items-end bg-gradient-to-br from-[#e8e2d6] to-[#d6cdbd] p-4">
        <span class="text-xs font-semibold tracking-wide text-[#6b665e] uppercase">{{ $article->material?->document?->source?->nameIn($language) }}</span>
    </div>
@endif
