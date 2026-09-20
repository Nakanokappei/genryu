<?php

use App\Acquisition\Domain\Identity\UrlNormalizer;

// ADR-0003: URL normalization rules, one assertion per rule.
it('normalizes the syntactic parts of a URL', function () {
    expect(UrlNormalizer::normalize('HTTPS://WWW.Example.ORG:443/News/./Item%7e1/../Item%7e1'))
        ->toBe('https://www.example.org/News/Item~1');
});

it('drops fragments and tracking parameters but keeps meaningful ones sorted by name', function () {
    expect(UrlNormalizer::normalize('https://www.example.org/news?utm_source=x&id=42&fbclid=abc&page=2&UTM_medium=y#top'))
        ->toBe('https://www.example.org/news?id=42&page=2');
});

it('keeps repeated parameter names in their original order', function () {
    expect(UrlNormalizer::normalize('https://www.example.org/s?tag=b&tag=a'))
        ->toBe('https://www.example.org/s?tag=b&tag=a');
});

it('removes a trailing slash everywhere except the root', function () {
    expect(UrlNormalizer::normalize('https://www.example.org/news/'))->toBe('https://www.example.org/news')
        ->and(UrlNormalizer::normalize('https://www.example.org/'))->toBe('https://www.example.org/')
        ->and(UrlNormalizer::normalize('https://www.example.org'))->toBe('https://www.example.org/');
});

it('honours extra parameters to strip from the profile', function () {
    expect(UrlNormalizer::normalize('https://www.example.org/news?id=1&session=zzz', ['session']))
        ->toBe('https://www.example.org/news?id=1');
});

it('rejects anything that is not an absolute http(s) URL', function (string $url) {
    expect(fn () => UrlNormalizer::normalize($url))->toThrow(InvalidArgumentException::class);
})->with(['/relative/path', 'ftp://example.org/x', 'javascript:alert(1)', 'not a url']);

it('resolves relative references against a base URL', function () {
    expect(UrlNormalizer::resolve('https://www.example.org/news/index.html', '../files/a.pdf'))
        ->toBe('https://www.example.org/files/a.pdf')
        ->and(UrlNormalizer::resolve('https://www.example.org/news/', '//cdn.example.org/x'))
        ->toBe('https://cdn.example.org/x');
});
