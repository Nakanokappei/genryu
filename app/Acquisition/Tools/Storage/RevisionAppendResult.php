<?php

namespace App\Acquisition\Tools\Storage;

use App\Acquisition\Domain\Models\DocumentRevision;

/**
 * Outcome of append_document_revision: the revision that now represents
 * the content, and whether this call created it. Idempotent re-appends
 * return created = false (AT-05).
 */
final readonly class RevisionAppendResult
{
    public function __construct(
        public DocumentRevision $revision,
        public bool $created,
    ) {}
}
