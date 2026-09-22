<?php

namespace App\Actions;

use App\Models\DocumentRevision;

/**
 * The checks a material goes through before it is kept: every item the
 * editorial policy lists is there; an item taken from the document
 * quotes it, and every quote really is in the lines of the revision it
 * names; an item filled from the model's general knowledge says so and
 * quotes nothing, so the two can always be told apart. A quote that is
 * nowhere in the document is the one error worth a call to repair — it
 * means the agent wrote something the source does not say. What the
 * agent concluded from a true quote is a matter for a person (人の判定),
 * not for a validator. Each problem is one line the agent can act on.
 */
class ValidateMaterial
{
    /**
     * The problems of an answer, none when it passes.
     *
     * @param  array<string, mixed>  $material
     * @param  list<string>  $items  what the policy lists
     * @return list<string>
     */
    public function __invoke(array $material, array $items, DocumentRevision $revision): array
    {
        $errors = [];
        $lines = $revision->lines();

        foreach (array_values(array_diff($items, array_keys($material))) as $missing) {
            $errors[] = "{$missing}: the item is missing";
        }

        foreach ($material as $item => $filled) {
            if (! is_array($filled)) {
                $errors[] = "{$item}: expected an object with a value and its quotes";

                continue;
            }

            $source = (string) ($filled['source'] ?? '');

            foreach ($filled['quotes'] ?? [] as $index => $quote) {
                $start = (int) ($quote['line_start'] ?? 0);
                $end = (int) ($quote['line_end'] ?? 0);
                $where = "{$item}, quote ".($index + 1);

                if ($start < 1 || $end < $start || $end > count($lines)) {
                    $errors[] = "{$where}: line range {$start}-{$end} is outside the document (1-".count($lines).')';

                    continue;
                }

                if (! self::quoted((string) ($quote['quote'] ?? ''), implode("\n", array_slice($lines, $start - 1, $end - $start + 1)))) {
                    $errors[] = "{$where}: the quote is not found verbatim in lines {$start}-{$end}";
                }
            }

            // What comes from the document says where; what comes from knowledge does not quote; what has no value comes from nowhere.
            if ($source === 'document' && ($filled['quotes'] ?? []) === []) {
                $errors[] = "{$item}: source is document but no quote is given";
            }

            if ($source === 'knowledge' && ($filled['quotes'] ?? []) !== []) {
                $errors[] = "{$item}: source is knowledge, which takes no quote";
            }

            if ($source === 'none' && ($filled['value'] ?? null) !== null) {
                $errors[] = "{$item}: source is none, so the value must be null";
            }

            if ($source !== 'none' && ($filled['value'] ?? null) === null) {
                $errors[] = "{$item}: no value, so the source must be none";
            }
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
}
