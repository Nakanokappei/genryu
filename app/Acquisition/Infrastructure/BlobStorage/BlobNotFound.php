<?php

namespace App\Acquisition\Infrastructure\BlobStorage;

use RuntimeException;

/**
 * No object exists for the requested blob URI.
 */
class BlobNotFound extends RuntimeException
{
    public static function forUri(string $uri): self
    {
        return new self("No blob stored at {$uri}.");
    }
}
