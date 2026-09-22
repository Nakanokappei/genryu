<?php

namespace App\Actions;

/**
 * The checks a material goes through before it is kept. A material is
 * the parts of an article, so the three an article cannot be written
 * without have to be there: the angle it is written on, the change it
 * reports, and the facts the primary source gives. The rest may be
 * absent — a document that says nothing about what came before leaves
 * `before` out rather than guessing. Whether the angle is a good one is
 * a person's call (人の判定), never a validator's. Each problem is one
 * line the agent can act on, and the repair call is given them as they
 * are.
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

        foreach (array_diff(self::REQUIRED, array_keys($material)) as $missing) {
            $errors[] = "{$missing}: missing; an article cannot be written without it";
        }

        // Nothing but the parts: a material carries no notes on itself.
        foreach (array_diff(array_keys($material), ProposeMaterial::PARTS) as $extra) {
            $errors[] = "{$extra}: not one of the parts asked for";
        }

        return $errors;
    }
}
