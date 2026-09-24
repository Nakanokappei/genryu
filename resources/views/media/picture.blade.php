{{-- An article's top image (ours, served by the site), or, without one yet, a quiet block with the source's name so the grid keeps its shape. --}}
@if (\App\Http\Controllers\MediaController::imagePathOf($article))
    <img src="{{ route('media.image', $article) }}" alt="" loading="lazy" class="{{ $class }}">
@else
    <div class="{{ $class }} flex items-end bg-gradient-to-br from-[#e8e2d6] to-[#d6cdbd] p-4">
        <span class="text-xs font-semibold tracking-wide text-[#6b665e] uppercase">{{ $article->material?->document?->source?->name }}</span>
    </div>
@endif
