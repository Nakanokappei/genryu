<?php

namespace App\Acquisition\Domain\Profile;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Tools\Parsers\ParserRegistry;
use App\Acquisition\Tools\ToolError;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a Source Profile document against the v1 JSON Schema and the
 * parser registry (ADR-0005). Nothing that fails here is ever persisted.
 */
final class SourceProfileValidator
{
    private const SCHEMA_PATH = 'schemas/source_profile.v1.schema.json';

    /**
     * Violations as "path: message" strings; empty when valid.
     *
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public function violations(array $profile): array
    {
        // Opis stops at the first error by default; operators need the full list.
        $validator = (new Validator)->setMaxErrors(100)->setStopAtFirstError(false);
        $schema = (string) file_get_contents(resource_path(self::SCHEMA_PATH));

        // Opis works on decoded JSON objects, not PHP associative arrays.
        $result = $validator->validate(json_decode(json_encode($profile, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR), $schema);

        $violations = [];

        if ($result->hasError()) {
            $error = $result->error();

            if ($error !== null) {
                /** @var array<string, list<string>> $formatted */
                $formatted = (new ErrorFormatter)->format($error, true);

                foreach ($formatted as $path => $messages) {
                    foreach ($messages as $message) {
                        $violations[] = "{$path}: {$message}";
                    }
                }
            }
        }

        // Schema syntax alone cannot know which parsers this build ships.
        foreach ((array) ($profile['parser_bindings'] ?? []) as $mediaType => $parserId) {
            if (is_string($parserId) && ! ParserRegistry::has($parserId)) {
                $violations[] = "/parser_bindings/{$mediaType}: unknown parser {$parserId}";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $profile
     *
     * @throws ToolError with ErrorCode::InvalidInput
     */
    public function assertValid(array $profile): void
    {
        $violations = $this->violations($profile);

        if ($violations !== []) {
            throw new ToolError(ErrorCode::InvalidInput, 'Source profile failed schema validation.', ['violations' => $violations]);
        }
    }
}
