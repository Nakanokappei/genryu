<?php

namespace App\Actions;

use App\Models\DocumentRevision;

/**
 * The checks a material's JSON goes through that a schema cannot make
 * (docs: the dossier design, §5 and §7.1): every quote is found in the
 * lines of the document revision it points at; ids are unique and every
 * reference points at something; a primary claim rests on spans only,
 * an inference on claims only, without cycles; a supported facet names
 * claims; a transition observed has two known, different states and a
 * primary root; an angle has its tension, lens, why-now evidence and an
 * inference claim with a primary root; the recommended angle is a
 * candidate. Each problem is one line the agent can act on in a repair.
 */
class ValidateMaterial
{
    /**
     * The problems of the extract phase's answer, none when it passes.
     *
     * @param  array<string, mixed>  $extract
     * @return list<string>
     */
    public function extract(array $extract, DocumentRevision $revision): array
    {
        $errors = [];
        $lines = $revision->lines();
        $spans = [];

        foreach ($extract['primary_spans'] ?? [] as $span) {
            $id = (string) ($span['id'] ?? '');

            if (isset($spans[$id])) {
                $errors[] = "span {$id}: duplicate id";
            }

            $spans[$id] = true;
            $start = (int) ($span['line_start'] ?? 0);
            $end = (int) ($span['line_end'] ?? 0);

            if ($start < 1 || $end < $start || $end > count($lines)) {
                $errors[] = "span {$id}: line range {$start}-{$end} is outside the document (1-".count($lines).')';

                continue;
            }

            $text = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));

            if (! self::quoted((string) ($span['quote'] ?? ''), $text)) {
                $errors[] = "span {$id}: the quote is not found verbatim in lines {$start}-{$end}";
            }
        }

        $claims = [];

        foreach ($extract['claims'] ?? [] as $claim) {
            $id = (string) ($claim['id'] ?? '');

            if (isset($claims[$id])) {
                $errors[] = "claim {$id}: duplicate id";
            }

            $claims[$id] = true;

            if (($claim['basis'] ?? []) === []) {
                $errors[] = "claim {$id}: a primary_evidence claim needs at least one span in its basis";
            }

            foreach ($claim['basis'] ?? [] as $basis) {
                if (! isset($spans[$basis])) {
                    $errors[] = "claim {$id}: basis {$basis} is not a span of primary_spans";
                }
            }
        }

        foreach ($extract['primary_evidence'] ?? [] as $name => $facet) {
            array_push($errors, ...self::facetErrors("primary_evidence.{$name}", $facet, $claims));
        }

        return $errors;
    }

    /**
     * The problems of the finalize phase's answer, none when it passes;
     * the extract phase's claims are the evidence it may rest on.
     *
     * @param  array<string, mixed>  $finalize
     * @param  array<string, mixed>  $extract
     * @return list<string>
     */
    public function finalize(array $finalize, array $extract): array
    {
        $errors = [];
        $primary = [];

        foreach ($extract['claims'] ?? [] as $claim) {
            $primary[(string) $claim['id']] = true;
        }

        // Inference claims: new ids, resting on existing claims, no cycles.
        $basisOf = [];

        foreach ($finalize['claims'] ?? [] as $claim) {
            $id = (string) ($claim['id'] ?? '');

            if (isset($primary[$id]) || isset($basisOf[$id])) {
                $errors[] = "claim {$id}: duplicate id (evidence claims keep their ids; inference claims need new ones)";
            }

            $basisOf[$id] = array_values(array_map(strval(...), $claim['basis'] ?? []));

            if ($basisOf[$id] === [] && ! in_array($claim['epistemic_status'] ?? '', ['unknown', 'insufficient_evidence'], true)) {
                $errors[] = "claim {$id}: an inference needs at least one claim in its basis";
            }
        }

        $all = [...array_fill_keys(array_keys($primary), true), ...array_fill_keys(array_keys($basisOf), true)];

        foreach ($basisOf as $id => $basis) {
            foreach ($basis as $ref) {
                if (! isset($all[$ref])) {
                    $errors[] = "claim {$id}: basis {$ref} is not a claim";
                } elseif ($ref === $id) {
                    $errors[] = "claim {$id}: rests on itself";
                }
            }
        }

        foreach (array_keys($basisOf) as $id) {
            if (self::cyclic((string) $id, $basisOf, [])) {
                $errors[] = "claim {$id}: circular basis";
            }
        }

        $hasPrimaryRoot = fn (string $id): bool => self::rootedInPrimary($id, $basisOf, $primary, []);

        // The transition.
        $transition = $finalize['technology_transition'] ?? [];
        $assessmentIds = array_map(strval(...), $transition['assessment_claim_ids'] ?? []);

        foreach ($assessmentIds as $ref) {
            if (! isset($all[$ref])) {
                $errors[] = "technology_transition: assessment claim {$ref} is not a claim";
            }
        }

        if (($transition['assessment'] ?? '') === 'observed_transition') {
            if (($transition['previous_state'] ?? 'unknown') === 'unknown' || ($transition['current_state'] ?? 'unknown') === 'unknown' || ($transition['previous_state'] ?? '') === ($transition['current_state'] ?? '')) {
                $errors[] = 'technology_transition: an observed transition needs two known, different states';
            }

            if ($assessmentIds === [] || ! array_any($assessmentIds, fn (string $ref): bool => isset($all[$ref]) && $hasPrimaryRoot($ref))) {
                $errors[] = 'technology_transition: an observed transition needs an assessment claim rooted in primary evidence';
            }
        }

        array_push($errors, ...self::facetErrors('technology_transition.frontier_transition', $transition['frontier_transition'] ?? [], $all));

        foreach ($finalize['engineering'] ?? [] as $name => $facet) {
            array_push($errors, ...self::facetErrors("engineering.{$name}", $facet, $all));
        }

        $editorial = $finalize['editorial'] ?? [];
        array_push($errors, ...self::facetErrors('editorial.why_it_matters', $editorial['why_it_matters'] ?? [], $all));

        // Tensions: both sides and the relationship name claims; the relationship is an inference.
        $tensions = [];

        foreach ($editorial['tensions'] ?? [] as $tension) {
            $id = (string) ($tension['id'] ?? '');
            $tensions[$id] = true;

            foreach (['left_claim_ids', 'right_claim_ids', 'relationship_claim_ids'] as $side) {
                $refs = array_map(strval(...), $tension[$side] ?? []);

                if ($refs === []) {
                    $errors[] = "tension {$id}: {$side} is empty";
                }

                foreach ($refs as $ref) {
                    if (! isset($all[$ref])) {
                        $errors[] = "tension {$id}: {$side} {$ref} is not a claim";
                    } elseif ($side === 'relationship_claim_ids' && ! isset($basisOf[$ref])) {
                        $errors[] = "tension {$id}: relationship claim {$ref} must be an inference";
                    }
                }
            }
        }

        $gaps = array_fill_keys(array_map(fn (array $gap): string => (string) $gap['id'], $editorial['missing_information'] ?? []), true);

        foreach ($editorial['missing_information'] ?? [] as $gap) {
            foreach ($gap['related_claim_ids'] ?? [] as $ref) {
                if (! isset($all[(string) $ref])) {
                    $errors[] = "missing_information {$gap['id']}: related claim {$ref} is not a claim";
                }
            }
        }

        foreach ($editorial['next_signals'] ?? [] as $signal) {
            foreach ($signal['related_claim_ids'] ?? [] as $ref) {
                if (! isset($all[(string) $ref])) {
                    $errors[] = "next_signal {$signal['id']}: related claim {$ref} is not a claim";
                }
            }
        }

        foreach ($editorial['reader_questions'] ?? [] as $question) {
            foreach ($question['answer_claim_ids'] ?? [] as $ref) {
                if (! isset($all[(string) $ref])) {
                    $errors[] = "reader_question {$question['id']}: answer claim {$ref} is not a claim";
                }
            }

            if (($question['status'] ?? '') === 'answered' && ($question['answer_claim_ids'] ?? []) === []) {
                $errors[] = "reader_question {$question['id']}: answered without an answer claim";
            }
        }

        // Angles.
        $candidates = [];

        foreach ($editorial['possible_angles'] ?? [] as $angle) {
            $id = (string) ($angle['id'] ?? '');

            if (($angle['decision'] ?? '') === 'candidate') {
                $candidates[$id] = true;
            }

            if (($angle['tension_ids'] ?? []) === []) {
                $errors[] = "angle {$id}: needs at least one tension";
            }

            foreach ($angle['tension_ids'] ?? [] as $ref) {
                if (! isset($tensions[(string) $ref])) {
                    $errors[] = "angle {$id}: tension {$ref} is not in tensions";
                }
            }

            if (($angle['lenses'] ?? []) === []) {
                $errors[] = "angle {$id}: needs at least one lens";
            }

            $whyNow = array_map(strval(...), $angle['why_now_primary_claim_ids'] ?? []);

            if ($whyNow === []) {
                $errors[] = "angle {$id}: why_now_primary_claim_ids is empty";
            }

            foreach ([...$whyNow, ...array_map(strval(...), $angle['supporting_primary_claim_ids'] ?? [])] as $ref) {
                if (! isset($primary[$ref])) {
                    $errors[] = "angle {$id}: {$ref} is not a primary_evidence claim";
                }
            }

            foreach ([...array_map(strval(...), $angle['counterpoint_claim_ids'] ?? [])] as $ref) {
                if (! isset($all[$ref])) {
                    $errors[] = "angle {$id}: counterpoint {$ref} is not a claim";
                }
            }

            foreach ($angle['missing_information_ids'] ?? [] as $ref) {
                if (! isset($gaps[(string) $ref])) {
                    $errors[] = "angle {$id}: missing information {$ref} is not in missing_information";
                }
            }

            $angleClaim = (string) ($angle['angle_claim_id'] ?? '');

            if (! isset($basisOf[$angleClaim])) {
                $errors[] = "angle {$id}: angle_claim_id {$angleClaim} must be an inference claim";
            } elseif (! $hasPrimaryRoot($angleClaim)) {
                $errors[] = "angle {$id}: angle claim {$angleClaim} is not rooted in primary evidence";
            }
        }

        $recommended = $editorial['recommended_angle_id'] ?? null;

        if ($recommended !== null && ! isset($candidates[(string) $recommended])) {
            $errors[] = "recommended_angle_id {$recommended} is not a candidate angle";
        }

        return $errors;
    }

    /**
     * Whether a quote is in a text, whitespace differences aside.
     */
    private static function quoted(string $quote, string $text): bool
    {
        $squeeze = fn (string $value): string => (string) preg_replace('/\s+/u', ' ', trim($value));

        return $quote !== '' && $squeeze($quote) !== '' && str_contains($squeeze($text), $squeeze($quote));
    }

    /**
     * @param  array<string, mixed>  $facet
     * @param  array<string, bool>  $claims
     * @return list<string>
     */
    private static function facetErrors(string $name, array $facet, array $claims): array
    {
        $errors = [];
        $ids = array_map(strval(...), $facet['claim_ids'] ?? []);

        if (($facet['status'] ?? '') === 'supported' && $ids === []) {
            $errors[] = "{$name}: supported but names no claim";
        }

        foreach ($ids as $ref) {
            if (! isset($claims[$ref])) {
                $errors[] = "{$name}: claim {$ref} is not a claim";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, list<string>>  $basisOf
     * @param  array<string, bool>  $seen
     */
    private static function cyclic(string $id, array $basisOf, array $seen): bool
    {
        if (isset($seen[$id])) {
            return true;
        }

        $seen[$id] = true;

        foreach ($basisOf[$id] ?? [] as $ref) {
            if (isset($basisOf[$ref]) && self::cyclic($ref, $basisOf, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a claim's basis reaches a primary_evidence claim.
     *
     * @param  array<string, list<string>>  $basisOf
     * @param  array<string, bool>  $primary
     * @param  array<string, bool>  $seen
     */
    private static function rootedInPrimary(string $id, array $basisOf, array $primary, array $seen): bool
    {
        if (isset($primary[$id])) {
            return true;
        }

        if (isset($seen[$id])) {
            return false;
        }

        $seen[$id] = true;

        foreach ($basisOf[$id] ?? [] as $ref) {
            if (self::rootedInPrimary($ref, $basisOf, $primary, $seen)) {
                return true;
            }
        }

        return false;
    }
}
