<?php

namespace App\Acquisition\Reprocessing;

/**
 * Outcome of an executed reprocess: counts plus every per-document failure,
 * so partial failure is visible rather than averaged away (plan §11).
 */
final class ReprocessReport
{
    public int $reprocessed = 0;

    public int $skippedExisting = 0;

    /** @var list<array{revision_id: int, stable_key: string, code: string, message: string}> */
    public array $failures = [];

    public function failed(): int
    {
        return count($this->failures);
    }
}
