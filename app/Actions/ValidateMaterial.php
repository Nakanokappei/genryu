<?php

namespace App\Actions;

/**
 * The checks a dossier goes through before it is kept. They are about
 * what the answer says of itself, not about whether it is right: a lens
 * that is there must have all five parts and at least one statement
 * taken from the primary source, an angle must point at a lens that is
 * there and be ranked from 1 without gaps, a state must be one of the
 * lifecycle's. Whether an inference is sound is a matter for a person
 * (人の判定), not for a validator. Each problem is one line the agent
 * can act on, and the repair call is given them as they are.
 */
class ValidateMaterial
{
    /**
     * The problems of a dossier, none when it passes.
     *
     * @param  array<string, mixed>  $dossier
     * @return list<string>
     */
    public function __invoke(array $dossier): array
    {
        $errors = [];
        $lenses = (array) ($dossier['editorial_lenses'] ?? []);

        foreach ($lenses as $name => $lens) {
            $lens = (array) $lens;

            // A lens is kept only when it can be told as a whole story: before, change, after, the tension and the angle.
            foreach (array_diff(ProposeMaterial::LENS_PARTS, array_keys($lens)) as $missing) {
                $errors[] = "{$name}: {$missing} is missing; drop the lens or fill it";
            }

            $claims = (array) ($lens['claims'] ?? []);

            if ($claims === []) {
                $errors[] = "{$name}: no claims; every lens rests on statements";
            }

            // CHANGE is about this document: a lens with nothing from the primary source is an opinion, not a reading.
            if ($claims !== [] && ! array_any($claims, fn (mixed $claim): bool => (is_array($claim) ? ($claim['type'] ?? '') : '') === 'primary_source')) {
                $errors[] = "{$name}: no claim of type primary_source; the change must touch this document";
            }

            $errors = [...$errors, ...self::claimErrors($claims, (string) $name)];
        }

        foreach (['previous_state', 'current_state'] as $field) {
            $state = ($dossier['technology_transition'] ?? [])[$field] ?? null;

            if ($state !== null && ! in_array($state, ProposeMaterial::STATES, true)) {
                $errors[] = "technology_transition.{$field}: \"{$state}\" is not one of the lifecycle states";
            }
        }

        $errors = [...$errors, ...self::claimErrors((array) (($dossier['technology_transition'] ?? [])['evidence'] ?? []), 'technology_transition')];

        foreach (array_values((array) ($dossier['recommended_angles'] ?? [])) as $index => $angle) {
            $angle = (array) $angle;
            $rank = $index + 1;

            if (($angle['rank'] ?? null) !== $rank) {
                $errors[] = 'recommended_angles: the ranks must run 1, 2, 3 in order';
            }

            if (! array_key_exists((string) ($angle['lens'] ?? ''), $lenses)) {
                $errors[] = "recommended_angles #{$rank}: lens \"".($angle['lens'] ?? '').'" is not among the lenses kept';
            }

            foreach ((array) ($angle['primary_evidence'] ?? []) as $claim) {
                if ((is_array($claim) ? ($claim['type'] ?? '') : '') !== 'primary_source') {
                    $errors[] = "recommended_angles #{$rank}: primary_evidence takes claims of type primary_source only";
                }
            }
        }

        return $errors;
    }

    /**
     * The problems of a list of claims: a statement, where it comes from
     * and what it rests on are what makes a claim answerable.
     *
     * @param  array<int, mixed>  $claims
     * @return list<string>
     */
    private static function claimErrors(array $claims, string $where): array
    {
        $errors = [];

        foreach (array_values($claims) as $index => $claim) {
            $claim = (array) $claim;
            $at = $where.', claim '.($index + 1);

            if (trim((string) ($claim['statement'] ?? '')) === '') {
                $errors[] = "{$at}: no statement";
            }

            if (! in_array($claim['type'] ?? '', ProposeMaterial::CLAIM_TYPES, true)) {
                $errors[] = "{$at}: type must be one of ".implode(' / ', ProposeMaterial::CLAIM_TYPES);
            }

            if (trim((string) ($claim['basis'] ?? '')) === '') {
                $errors[] = "{$at}: no basis; say what it rests on";
            }
        }

        return $errors;
    }
}
