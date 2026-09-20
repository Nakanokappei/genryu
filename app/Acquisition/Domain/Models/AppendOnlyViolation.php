<?php

namespace App\Acquisition\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Thrown when code tries to update or delete an append-only record.
 */
class AppendOnlyViolation extends LogicException
{
    /**
     * Build the exception for a blocked update.
     */
    public static function update(Model $model): self
    {
        return new self(sprintf('%s is append-only; updating record %s is not allowed.', $model::class, $model->getKey()));
    }

    /**
     * Build the exception for a blocked delete.
     */
    public static function delete(Model $model): self
    {
        return new self(sprintf('%s is append-only; deleting record %s is not allowed.', $model::class, $model->getKey()));
    }
}
