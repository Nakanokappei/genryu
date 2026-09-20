<?php

use App\Acquisition\Domain\Enums\BlobLayer;
use App\Acquisition\Infrastructure\BlobStorage\BlobCorrupted;
use App\Acquisition\Infrastructure\BlobStorage\BlobNotFound;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Infrastructure\BlobStorage\FilesystemBlobStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('acquisition');
    $this->store = app(BlobStore::class);
});

// AT-03: bytes written and bytes read back must hash identically.
it('stores bytes and reads back the identical content', function () {
    $bytes = "<html><body>DARPA news \xE2\x80\x94 2026</body></html>";

    $ref = $this->store->put(BlobLayer::Raw, $bytes, 'text/html');

    expect($ref->sha256)->toBe(hash('sha256', $bytes))
        ->and($ref->bytes)->toBe(strlen($bytes))
        ->and($ref->mediaType)->toBe('text/html')
        ->and($ref->uri)->toStartWith('acquisition://raw/')
        ->and($this->store->exists($ref->uri))->toBeTrue()
        ->and($this->store->get($ref->uri))->toBe($bytes);
});

it('resolves to the same reference for identical bytes and keeps a single object', function () {
    $first = $this->store->put(BlobLayer::Raw, 'same bytes', 'text/plain');
    $second = $this->store->put(BlobLayer::Raw, 'same bytes', 'text/plain');

    expect($second->uri)->toBe($first->uri)
        ->and(Storage::disk('acquisition')->allFiles('raw'))->toHaveCount(1)
        ->and(Storage::disk('acquisition')->allFiles('tmp'))->toBeEmpty();
});

it('keeps RAW and NORMALIZED objects apart even when their bytes match', function () {
    $raw = $this->store->put(BlobLayer::Raw, 'identical', 'text/plain');
    $normalized = $this->store->put(BlobLayer::Normalized, 'identical', 'text/markdown');

    expect($raw->uri)->not->toBe($normalized->uri)
        ->and($raw->sha256)->toBe($normalized->sha256);
});

// AT-03: RAW has no update API. The port exposes exactly put / get / exists.
it('exposes no delete or overwrite operation', function () {
    $methods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(BlobStore::class))->getMethods(),
    );

    expect($methods)->toEqualCanonicalizing(['put', 'get', 'exists']);
});

it('throws when reading a URI that was never stored', function () {
    $missing = FilesystemBlobStore::SCHEME.FilesystemBlobStore::keyFor(BlobLayer::Raw, str_repeat('0', 64));

    expect(fn () => $this->store->get($missing))->toThrow(BlobNotFound::class);
});

it('refuses to hand back bytes that no longer match their address', function () {
    $ref = $this->store->put(BlobLayer::Raw, 'original', 'text/plain');

    // Simulate on-disk corruption behind the store's back.
    Storage::disk('acquisition')->put(FilesystemBlobStore::keyFor(BlobLayer::Raw, $ref->sha256), 'tampered');

    expect(fn () => $this->store->get($ref->uri))->toThrow(BlobCorrupted::class)
        ->and(fn () => $this->store->put(BlobLayer::Raw, 'original', 'text/plain'))->toThrow(BlobCorrupted::class);
});
