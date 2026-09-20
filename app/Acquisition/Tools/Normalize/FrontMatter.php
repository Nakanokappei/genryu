<?php

namespace App\Acquisition\Tools\Normalize;

/**
 * Minimal deterministic YAML emitter for front matter. Handles the scalar,
 * list and map shapes the Document Model uses; strings are always JSON
 * quoted, which is valid YAML and sidesteps every quoting edge case.
 */
final class FrontMatter
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public static function emit(array $fields): string
    {
        return self::map($fields, 0);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function map(array $fields, int $indent): string
    {
        $pad = str_repeat('  ', $indent);
        $out = '';

        foreach ($fields as $key => $value) {
            if (is_array($value) && $value === []) {
                $out .= "{$pad}{$key}: []\n";
            } elseif (is_array($value) && array_is_list($value)) {
                $out .= "{$pad}{$key}:\n".self::list($value, $indent + 1);
            } elseif (is_array($value)) {
                $out .= "{$pad}{$key}:\n".self::map($value, $indent + 1);
            } else {
                $out .= "{$pad}{$key}: ".self::scalar($value)."\n";
            }
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function list(array $items, int $indent): string
    {
        $pad = str_repeat('  ', $indent);
        $out = '';

        foreach ($items as $item) {
            if (is_array($item) && ! array_is_list($item)) {
                $nested = self::map($item, $indent + 1);
                // First key shares the line with the dash.
                $out .= "{$pad}- ".ltrim(substr($nested, strlen($pad) + 2));
            } else {
                $out .= "{$pad}- ".self::scalar($item)."\n";
            }
        }

        return $out;
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };
    }
}
