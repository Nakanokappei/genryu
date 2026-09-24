<?php

namespace App\Actions;

/**
 * Checks a material has the required parts and nothing but parts; each
 * problem is one line handed to the repair call.
 */
class ValidateMaterial
{
    /** The parts a material cannot do without. */
    private const REQUIRED = ['angle', 'change', 'facts'];

    /**
     * The problems of a material, none when it passes.
     *
     * @param  array<string, mixed>  $material
     * @return list<string>
     */
    public function __invoke(array $material): array
    {
        $errors = [];

        // Required parts missing.
        foreach (array_diff(self::REQUIRED, array_keys($material)) as $missing) {
            $errors[] = "{$missing}: missing; an article cannot be written without it";
        }

        // Keys that are not parts.
        foreach (array_diff(array_keys($material), ProposeMaterial::PARTS) as $extra) {
            $errors[] = "{$extra}: not one of the parts asked for";
        }

        return $errors;
    }
}
