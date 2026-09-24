<?php

use App\Crawl\Url;

// A relative link resolves against the directory of its page: the path itself when it ends in "/".
it('resolves a relative link against the directory of the page', function (string $href, string $page, string $expected) {
    expect(Url::absolute($href, $page))->toBe($expected);
})->with([
    'page in a directory' => ['item.html', 'https://ex.com/news/', 'https://ex.com/news/item.html'],
    'page as a file' => ['item.html', 'https://ex.com/news/index.html', 'https://ex.com/news/item.html'],
    'page at the root' => ['item.html', 'https://ex.com/', 'https://ex.com/item.html'],
    'no path' => ['item.html', 'https://ex.com', 'https://ex.com/item.html'],
    'up a level from a directory' => ['../img/a.png', 'https://ex.com/news/2026/', 'https://ex.com/news/img/a.png'],
]);
