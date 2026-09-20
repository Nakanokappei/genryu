<?php

namespace App\Acquisition\Infrastructure\BlobStorage;

use App\Acquisition\Domain\Enums\BlobLayer;

/**
 * Port for artifact bytes (ADR-0002). Deliberately has no delete and no
 * overwrite: RAW is append-only (AT-03), and content addressing makes a
 * repeated put of identical bytes a harmless no-op.
 */
interface BlobStore
{
    /**
     * Store bytes under their content address and return the reference.
     * Putting bytes that already exist returns the existing reference.
     *
     * @throws BlobCorrupted when an existing object no longer matches its hash
     */
    public function put(BlobLayer $layer, string $bytes, string $mediaType): BlobRef;

    /**
     * Read the bytes behind a blob URI, verifying them against the hash
     * embedded in the URI before returning.
     *
     * @throws BlobNotFound
     * @throws BlobCorrupted
     */
    public function get(string $uri): string;

    /**
     * Whether an object exists for the given blob URI.
     */
    public function exists(string $uri): bool;
}
