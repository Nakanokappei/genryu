<?php

namespace App\Acquisition\Infrastructure\BlobStorage;

/**
 * Everything the database needs to know about a stored blob. The URI is
 * what goes into blob_uri columns; sha256 doubles as the content address.
 */
final readonly class BlobRef
{
    public function __construct(
        public string $uri,
        public string $sha256,
        public int $bytes,
        public string $mediaType,
    ) {}
}
