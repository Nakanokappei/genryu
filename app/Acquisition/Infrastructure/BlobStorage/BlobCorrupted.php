<?php

namespace App\Acquisition\Infrastructure\BlobStorage;

use RuntimeException;

/**
 * The stored bytes no longer hash to the address they were stored under.
 * This is never expected; it means the storage medium was tampered with or
 * has failed, and callers must not treat the bytes as the original.
 */
class BlobCorrupted extends RuntimeException
{
    public static function forUri(string $uri, string $expectedSha256, string $actualSha256): self
    {
        return new self("Blob at {$uri} hashes to {$actualSha256}, expected {$expectedSha256}.");
    }
}
