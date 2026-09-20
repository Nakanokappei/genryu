<?php

namespace App\Acquisition\Tools;

/**
 * Deterministic SHA-256 of a request payload for the audit trail. Keys are
 * sorted recursively so two equal requests always share one digest, and the
 * body itself never has to be stored to recognise a repeated call.
 */
final class RequestDigest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function of(array $payload): string
    {
        return hash('sha256', json_encode(self::sortKeys($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Sort associative keys at every depth; lists keep their order because
     * order is meaningful there.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
