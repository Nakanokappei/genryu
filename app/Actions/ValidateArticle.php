<?php

namespace App\Actions;

use App\Enums\Language;
use App\Support\Hedges;

/**
 * Checks an article body (without headline or lead) for shape and length;
 * each problem is one line handed to the rewrite. A paragraph is any
 * non-empty line that is not a heading.
 */
class ValidateArticle
{
    /** Allowed length without the sources: characters (CJK) or words. */
    public const LENGTHS = ['characters' => [800, 1200], 'words' => [500, 800]];

    /** ## sections before the sources: 承, 転, 結. */
    public const SECTIONS = 3;

    /** How a lead must not begin: by reporting a study rather than stating the claim. */
    private const REPORTING_OPENING = '/^\s*(?:A (?:new )?study|New research|Research(?:ers)? (?:shows?|finds?|found|have)|Scientists|According to|ある研究|研究によると|研究チームは|Eine (?:neue )?Studie|Laut |Forschende|Une (?:nouvelle )?étude|Selon |Des chercheurs|一项(?:新)?研究|研究显示|据)/iu';

    /** How many hedges a lead may carry. */
    public const LEAD_HEDGES = 1;

    /** The sources heading in any language, optionally followed by a translation after a slash. */
    private const SOURCES_HEADING = '/^(出典|出处|出處|출처|Sources?|Quellen)(\s*[\/／|]\s*\S.*)?$/iu';

    /**
     * The body's problems, none when it passes.
     *
     * @return list<string>
     */
    public function __invoke(string $body, ?string $language, string $headline, string $url): array
    {
        return [...self::shapeProblems($body, $headline, $url), ...self::lengthProblems($body, $language)];
    }

    /**
     * A lead's problems, one line each: it states the claim, not the report of a study, and hedges at most once.
     *
     * @return list<string>
     */
    public static function leadProblems(string $lead): array
    {
        $problems = [];

        // Begins by reporting a study.
        if (preg_match(self::REPORTING_OPENING, $lead, $opening) === 1) {
            $problems[] = 'The lead begins by reporting a study ("'.trim($opening[0]).'"); begin with the claim itself, the angle of the material.';
        }

        // Hedges more than once.
        if (($hedges = Hedges::count($lead)) > self::LEAD_HEDGES) {
            $problems[] = "The lead hedges {$hedges} times (may, could, 可能性, かもしれない …); keep at most one and state the rest plainly.";
        }

        return $problems;
    }

    /**
     * Shape problems, one line each.
     *
     * @return list<string>
     */
    public static function shapeProblems(string $body, string $headline, string $url): array
    {
        $lines = array_values(array_filter(array_map(trim(...), preg_split('/\R/u', $body) ?: []), fn (string $line): bool => $line !== ''));
        $problems = [];

        // Empty body.
        if ($lines === []) {
            return ['The body is empty.'];
        }

        // Must start with the opening, not a heading.
        if (str_starts_with($lines[0], '#')) {
            $problems[] = 'The body starts with a heading; it must start with the opening, with no heading.';
        }

        // Count opening paragraphs, then paragraphs under each ## heading.
        $opening = 0;
        $headings = [];
        $paragraphs = [];
        // Text under the latest heading (at the end, the sources).
        $underLast = '';

        foreach ($lines as $line) {
            // Heading, opening paragraph, or paragraph under a heading.
            if (preg_match('/^(#+)\s*(.*)$/u', $line, $match) === 1) {
                // Only ## is allowed.
                if ($match[1] !== '##') {
                    $problems[] = "Only ## headings may be used; found \"{$line}\".";

                    continue;
                }

                $headings[] = trim($match[2]);
                $paragraphs[] = 0;
                $underLast = '';
            } elseif ($headings === []) {
                $opening++;
            } else {
                $paragraphs[array_key_last($paragraphs)]++;
                $underLast .= $line."\n";
            }
        }

        // The opening (起) must be there.
        if ($opening < 1) {
            $problems[] = 'Before the first ## heading there must be the opening, at least one paragraph; found none.';
        }

        // Last section is the sources, linking the primary source.
        $isSources = fn (string $heading): bool => preg_match(self::SOURCES_HEADING, $heading) === 1;
        $last = array_key_last($headings);

        if ($last === null || ! $isSources($headings[$last])) {
            $problems[] = 'The body must end with a ## 出典 section (Sources in other languages).';
        } elseif (! str_contains($underLast, $url)) {
            $problems[] = "The sources section must link to the primary source: {$url}";
        }

        $content = array_filter($headings, fn (string $heading): bool => ! $isSources($heading));

        // Exactly SECTIONS content headings.
        if (count($content) !== self::SECTIONS) {
            $problems[] = 'There must be exactly '.self::SECTIONS.' ## headings before the sources, one each for 承, 転 and 結; found '.count($content).'.';
        }

        foreach ($content as $index => $heading) {
            // No label headings (起承転結), no repeated headline, no empty section.
            if (preg_match('/^[起承転結](\s|[:：]|$)/u', $heading) === 1) {
                $problems[] = "The heading \"{$heading}\" is a label; write a plain phrase.";
            }

            if ($heading === trim($headline)) {
                $problems[] = 'A heading repeats the headline.';
            }

            if ($paragraphs[$index] === 0) {
                $problems[] = "The section \"{$heading}\" has no text under it.";
            }
        }

        return $problems;
    }

    /**
     * Length problem, if any, as one line.
     *
     * @return list<string>
     */
    private static function lengthProblems(string $body, ?string $language): array
    {
        $length = self::lengthOf($body, $language);

        return $length['off'] === 0 ? [] : ["It is {$length['count']} {$length['unit']} long, not counting the sources; it must be {$length['min']}–{$length['max']} {$length['unit']}."];
    }

    /**
     * The body's length without sources and Markdown marks: characters
     * without spaces (CJK, by language or else script) or words. `off` is
     * the distance outside the range, negative when short.
     *
     * @return array{count: int, unit: string, min: int, max: int, off: int}
     */
    public static function lengthOf(string $body, ?string $language): array
    {
        $text = preg_replace('/^#{1,6}\s*(出典|出处|出處|출처|Sources?|Quellen)\b.*\z/imsu', '', $body) ?? $body;
        $text = preg_replace('/^#{1,6}\s*|\[([^\]]*)\]\([^)]*\)|[*_`>]/mu', '$1', $text) ?? $text;
        $isCjk = $language === null ? preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1 : (Language::tryFrom($language)?->countsCharacters() ?? false);
        $unit = $isCjk ? 'characters' : 'words';
        $count = $unit === 'characters'
            ? mb_strlen(preg_replace('/\s+/u', '', $text) ?? $text)
            : count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        [$min, $max] = self::LENGTHS[$unit];

        return ['count' => $count, 'unit' => $unit, 'min' => $min, 'max' => $max, 'off' => $count < $min ? $count - $min : max(0, $count - $max)];
    }
}
