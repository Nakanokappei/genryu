<?php

namespace App\Acquisition\Tools;

use App\Acquisition\Domain\Enums\ErrorCode;
use Illuminate\Support\Facades\Validator;

/**
 * Shared payload validation for Tool requests. Wraps Laravel's validator so
 * every Tool reports schema violations as the same INVALID_INPUT error.
 */
final class RequestValidation
{
    /**
     * Validate and return the payload restricted to the declared rules.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ToolError
     */
    public static function validate(array $payload, array $rules): array
    {
        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            throw new ToolError(
                ErrorCode::InvalidInput,
                'Tool request failed validation.',
                ['violations' => $validator->errors()->toArray()],
            );
        }

        return $validator->validated();
    }
}
