<?php

use App\Crawl\PublishedDate;

// The printed date wins over a printed weekday that disagrees with it; a time is kept only with its zone.
it('reads a printed date whatever weekday is printed with it', function (string $printed, string $expected) {
    expect(PublishedDate::parse($printed))->toBe($expected);
})->with([
    'wrong weekday' => ['Wed, 24 Sep 2026 10:00 GMT', '2026-09-24T10:00:00+00:00'],
    'right weekday' => ['Thu, 24 Sep 2026 10:00 GMT', '2026-09-24T10:00:00+00:00'],
    'full weekday name, wrong' => ['Wednesday, 24 September 2026', '2026-09-24'],
    'wrong weekday, no zone' => ['Wed, 24 Sep 2026 10:00', '2026-09-24'],
    'no weekday' => ['24 Sep 2026', '2026-09-24'],
    'Japanese' => ['2026年9月24日', '2026-09-24'],
]);
