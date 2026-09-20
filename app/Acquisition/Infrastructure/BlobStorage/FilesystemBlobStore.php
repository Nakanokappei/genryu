<?php

namespace App\Acquisition\Infrastructure\BlobStorage;

use App\Acquisition\Domain\Enums\BlobLayer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * BlobStore on a Laravel filesystem disk (the `acquisition` disk). Works
 * unchanged on the local driver and on S3 because it only ever uses the
 * byte-oriented API, never ->path() (ADR-0002).
 *
 * Keys are content-addressed: `<layer>/<sha[0..2]>/<sha[2..4]>/<sha>`.
 */
final class FilesystemBlobStore implements BlobStore
{
    /**
     * URI scheme that marks a blob as living on the acquisition disk.
     */
    public const SCHEME = 'acquisition://';

    public function __construct(private Filesystem $disk) {}

    /**
     * {@inheritDoc}
     */
    public function put(BlobLayer $layer, string $bytes, string $mediaType): BlobRef
    {
        $sha256 = hash('sha256', $bytes);
        $key = self::keyFor($layer, $sha256);
        $ref = new BlobRef(self::SCHEME.$key, $sha256, strlen($bytes), $mediaType);

        // Identical bytes already stored: nothing to write, but confirm the
        // stored copy is still intact so a corrupted object cannot hide
        // behind a successful-looking put.
        if ($this->disk->exists($key)) {
            $this->verify($key, $sha256);

            return $ref;
        }

        // Write through a temporary key so an interrupted write can never
        // leave a partial object under the content address. If a concurrent
        // writer stored the same bytes meanwhile, the move replaces them with
        // an identical object, so the "never overwrite" rule still holds.
        $temporaryKey = 'tmp/'.Str::uuid().'-'.$sha256;

        if (! $this->disk->put($temporaryKey, $bytes)) {
            throw new RuntimeException("Failed to write blob {$key}.");
        }

        if (! $this->disk->move($temporaryKey, $key)) {
            $this->disk->delete($temporaryKey);

            throw new RuntimeException("Failed to move blob into place at {$key}.");
        }

        return $ref;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $uri): string
    {
        $key = self::keyFromUri($uri);

        if (! $this->disk->exists($key)) {
            throw BlobNotFound::forUri($uri);
        }

        $bytes = $this->disk->get($key);

        if ($bytes === null) {
            throw BlobNotFound::forUri($uri);
        }

        // The URI carries the expected hash, so every read is a fidelity check.
        $expected = basename($key);
        $actual = hash('sha256', $bytes);

        if (! hash_equals($expected, $actual)) {
            throw BlobCorrupted::forUri($uri, $expected, $actual);
        }

        return $bytes;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $uri): bool
    {
        return $this->disk->exists(self::keyFromUri($uri));
    }

    /**
     * Content-addressed key for a layer and hash.
     */
    public static function keyFor(BlobLayer $layer, string $sha256): string
    {
        return sprintf('%s/%s/%s/%s', $layer->value, substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256);
    }

    /**
     * Strip the scheme from a blob URI to get the disk key.
     */
    private static function keyFromUri(string $uri): string
    {
        if (! str_starts_with($uri, self::SCHEME)) {
            throw new RuntimeException("Not an acquisition blob URI: {$uri}");
        }

        return substr($uri, strlen(self::SCHEME));
    }

    /**
     * Re-read an existing object and confirm it still matches its address.
     */
    private function verify(string $key, string $expectedSha256): void
    {
        $bytes = $this->disk->get($key) ?? '';
        $actual = hash('sha256', $bytes);

        if (! hash_equals($expectedSha256, $actual)) {
            throw BlobCorrupted::forUri(self::SCHEME.$key, $expectedSha256, $actual);
        }
    }
}
