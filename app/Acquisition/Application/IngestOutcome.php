<?php

namespace App\Acquisition\Application;

use App\Acquisition\Domain\Models\Document;
use App\Acquisition\Domain\Models\DocumentRevision;
use App\Acquisition\Domain\Models\NormalizedArtifact;
use App\Acquisition\Domain\Models\RawArtifact;

/**
 * What ingesting one fetched resource did: which document it belongs to,
 * whether content changed, and whether the result met the profile's quality
 * expectations.
 */
final readonly class IngestOutcome
{
    public const NEW = 'new';

    public const REVISED = 'revised';

    public const UNCHANGED = 'unchanged';

    public function __construct(
        public RawArtifact $raw,
        public Document $document,
        public DocumentRevision $revision,
        public NormalizedArtifact $normalized,
        public string $change,
        public bool $qualityPassed,
    ) {}
}
