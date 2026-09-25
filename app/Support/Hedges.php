<?php

namespace App\Support;

/**
 * Hedging words (may, could, 可能性, かもしれない …) in the languages originals
 * are written in. "can" / できる states what is possible and is not one.
 */
class Hedges
{
    private const PATTERN = '/\b(?:may|might|could|possibly|perhaps|potentially|likely|könnten?|möglicherweise|vielleicht|eventuell|pourrait|pourraient|peut-être|possiblement|éventuellement|probablement)\b|可能性|かもしれ|ことがある|だろう|或许|也许|可能会|或将|수도 있|가능성|지도 모른/iu';

    /** How many hedges a text has. */
    public static function count(string $text): int
    {
        return (int) preg_match_all(self::PATTERN, $text);
    }
}
