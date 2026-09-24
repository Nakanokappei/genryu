{{-- The frame of the media site (Technology Watch as a reader sees it): masthead, languages, the page, and what readers are told about how it is made. --}}
@php($text = \App\Http\Controllers\MediaController::TEXT[$language])
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Technology Watch')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600&family=Noto+Serif+JP:wght@600;800&family=Source+Serif+4:opsz,wght@8..60,600;8..60,800&display=swap">
    @vite(['resources/css/app.css'])
    <style>
        .media-serif { font-family: 'Source Serif 4', 'Noto Serif JP', 'Noto Serif TC', 'Noto Serif SC', 'Noto Serif KR', Georgia, serif; }
        .media-sans { font-family: 'Noto Sans JP', 'Noto Sans TC', 'Noto Sans SC', 'Noto Sans KR', system-ui, sans-serif; }
        /* The lead (above the separator line of the article's Markdown) sits apart from the body; the body opens on a drop cap. */
        .media-article [data-lead] { font-size: 1.22rem; line-height: 1.85; color: #3d3a35; padding-bottom: 1.75rem; margin-bottom: 2.25rem; border-bottom: 1px solid #d9d4ca; }
        .media-article [data-lead] p { margin: 0; }
        .media-article [data-body] > p:first-child::first-letter { float: left; font-size: 3.6em; line-height: 0.82; font-weight: 800; margin: 0.06em 0.12em 0 0; color: #8a2f1d; }
    </style>
</head>
<body class="media-sans min-h-screen bg-[#faf8f4] text-[#1c1b19] antialiased">
    <header class="border-b-4 border-double border-[#1c1b19] bg-[#faf8f4]">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 pt-3 text-xs text-[#6b665e]">
            <span>{{ now(\App\Enums\Language::tryFrom($language)?->timezone() ?? 'UTC')->isoFormat('LL') }}</span>
            <nav class="flex flex-wrap gap-x-3 gap-y-1" aria-label="Languages">
                @foreach ($languages as $code)
                    <a href="{{ route('media.language', $code) }}" class="{{ $code === $language ? 'font-semibold text-[#1c1b19] underline underline-offset-4' : 'hover:text-[#1c1b19]' }}">{{ \App\Enums\Language::nameOf($code) }}</a>
                @endforeach
            </nav>
        </div>
        <div class="mx-auto max-w-6xl px-4 pt-4 pb-5 text-center">
            <a href="{{ route('media.language', $language) }}" class="media-serif block text-4xl font-extrabold tracking-tight sm:text-6xl">Technology Watch</a>
            <p class="mt-2 text-sm text-[#6b665e]">{{ $text['tagline'] }}</p>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @yield('content')
    </main>

    <footer class="mt-12 border-t border-[#d9d4ca] bg-[#f1ede4]">
        <div class="mx-auto max-w-6xl space-y-2 px-4 py-6 text-xs leading-relaxed text-[#6b665e]">
            <p>{{ $text['disclosure'] }}</p>
            <p>{{ $text['demo'] }} <span class="media-serif font-semibold">Genryu</span></p>
        </div>
    </footer>
</body>
</html>
