<?php

namespace App\Acquisition\Reprocessing;

/**
 * What a reprocess would touch, computed without writing anything. This is
 * the `--dry-run` output (plan §11).
 */
final readonly class ReprocessPlan
{
    public function __construct(
        public int $revisions,
        public int $alreadyProcessed,
        public int $toProcess,
        public int $rawBytes,
    ) {}
}
