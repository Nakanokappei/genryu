<?php

use App\Acquisition\Domain\Identity\StableKey;
use App\Acquisition\Domain\Identity\StableKeyResolver;

// ADR-0003: derivation order GUID -> canonical -> final URL.
it('prefers the feed GUID when present', function () {
    $key = StableKeyResolver::resolve('tag:example.org,2026:123', 'https://www.example.org/a', 'https://www.example.org/b');

    expect($key->key)->toBe('guid:tag:example.org,2026:123')
        ->and($key->rule)->toBe(StableKey::RULE_FEED_GUID);
});

it('falls back to the normalized canonical URL', function () {
    $key = StableKeyResolver::resolve(null, 'https://www.example.org/news/item/?utm_source=x', 'https://www.example.org/other');

    expect($key->key)->toBe('url:https://www.example.org/news/item')
        ->and($key->rule)->toBe(StableKey::RULE_CANONICAL);
});

it('uses the final URL when the canonical is missing or unusable', function () {
    expect(StableKeyResolver::resolve('', '', 'https://www.example.org/news/item/#x'))
        ->toEqual(new StableKey('url:https://www.example.org/news/item', StableKey::RULE_URL))
        ->and(StableKeyResolver::resolve(null, '/relative/canonical', 'https://www.example.org/news/item'))
        ->toEqual(new StableKey('url:https://www.example.org/news/item', StableKey::RULE_URL));
});

it('gives the same key to URL variants that differ only in tracking noise', function () {
    $a = StableKeyResolver::resolve(null, null, 'https://www.example.org/news/item?utm_campaign=a');
    $b = StableKeyResolver::resolve(null, null, 'https://WWW.example.org/news/item/');

    expect($a->key)->toBe($b->key);
});
