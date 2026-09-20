<?php

namespace App\Acquisition\Domain\Models\Concerns;

use App\Acquisition\Domain\Models\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes an Eloquent model insert-only. RAW artifacts, revisions and parser
 * outputs are provenance: once written they must never change or vanish
 * (plan §3.4, §10; AT-03, AT-06). Any attempt to update or delete through
 * Eloquent throws before a query is issued.
 */
trait AppendOnly
{
    /**
     * Register the guards when the model boots.
     */
    public static function bootAppendOnly(): void
    {
        static::updating(function (Model $model): void {
            throw AppendOnlyViolation::update($model);
        });

        static::deleting(function (Model $model): void {
            throw AppendOnlyViolation::delete($model);
        });
    }
}
