<?php

namespace App\Actions;

/**
 * The checks the body of an article goes through before it is kept: its
 * shape and its length, both of which can be counted rather than judged.
 * The shape is the one the article layer of the editorial policy asks
 * for — a lead, the opening (起) with no heading of its own, 承 / 転 / 結
 * under ## headings, and a final sources section linking to the primary
 * source; the headline is not in the body, the screen puts it above as
 * its # heading. Each problem is one line the agent can act on, and the
 * rewrite is given them as they are. Whether the article is any good is
 * not a validator's call.
 *
 * Every non-empty line is a block of its own (models write paragraphs one
 * newline apart; App\Models\Article::separateBlocks sets them apart when
 * the body is kept), so a paragraph here is a line that is not a heading.
 */
class ValidateArticle
{
    /** How long a body may be, not counting its sources: characters in Chinese or Japanese, words otherwise. */
    public const LENGTHS = ['characters' => [800, 1200], 'words' => [500, 800]];

    /** How many ## headings come between the opening and the sources: 承, 転 and 結. */
    public const SECTIONS = 3;

    /** The heading of the sources section, in the languages an article is written in. */
    private const SOURCES_HEADING = '/^(出典|出处|出處|Sources?|Quellen)$/iu';

    /**
     * The problems of a body, none when it passes.
     *
     * @return list<string>
     */
    public function __invoke(string $body, ?string $language, string $headline, string $url): array
    {
        return [...self::shapeProblems($body, $headline, $url), ...self::lengthProblems($body, $language)];
    }

    /**
     * What is wrong with the shape of a body, one line per problem.
     *
     * @return list<string>
     */
    public static function shapeProblems(string $body, string $headline, string $url): array
    {
        $lines = array_values(array_filter(array_map(trim(...), preg_split('/\R/u', $body) ?: []), fn (string $line): bool => $line !== ''));
        $problems = [];

        if ($lines === []) {
            return ['The body is empty.'];
        }

        // The lead comes first: a body that opens on a heading has lost it, or repeats the headline.
        if (str_starts_with($lines[0], '#')) {
            $problems[] = 'The body starts with a heading; it must start with the lead, one paragraph with no heading.';
        }

        // Split the body at its ## headings: what comes before the first, then each heading with the paragraphs under it.
        $opening = 0;
        $headings = [];
        $paragraphs = [];
        // The text under the latest heading, which at the end is the sources section's.
        $underLast = '';

        foreach ($lines as $line) {
            if (preg_match('/^(#+)\s*(.*)$/u', $line, $match) === 1) {
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

        // The lead and then the opening (起), neither under a heading.
        if ($opening < 2) {
            $problems[] = "Before the first ## heading there must be the lead and then the opening, at least two paragraphs; found {$opening}.";
        }

        // The sources close the article and link to the primary source.
        $isSources = fn (string $heading): bool => preg_match(self::SOURCES_HEADING, $heading) === 1;
        $last = array_key_last($headings);

        if ($last === null || ! $isSources($headings[$last])) {
            $problems[] = 'The body must end with a ## 出典 section (Sources in other languages).';
        } elseif (! str_contains($underLast, $url)) {
            $problems[] = "The sources section must link to the primary source: {$url}";
        }

        $content = array_filter($headings, fn (string $heading): bool => ! $isSources($heading));

        if (count($content) !== self::SECTIONS) {
            $problems[] = 'There must be exactly '.self::SECTIONS.' ## headings before the sources, one each for 承, 転 and 結; found '.count($content).'.';
        }

        foreach ($content as $index => $heading) {
            // A heading is a plain phrase, not the name of the part it heads, and not the headline again.
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
     * What is wrong with the length of a body: nothing, or one line.
     *
     * @return list<string>
     */
    public static function lengthProblems(string $body, ?string $language): array
    {
        $length = self::lengthOf($body, $language);

        return $length['off'] === 0 ? [] : ["It is {$length['count']} {$length['unit']} long, not counting the sources; it must be {$length['min']}–{$length['max']} {$length['unit']}."];
    }

    /**
     * The length of a body as the policy counts it: the sources section
     * (the heading that names 出典 or Sources, and what follows) and the
     * Markdown marks left out; characters without spaces in Chinese or
     * Japanese (by the language the agent named, else by the script),
     * words otherwise. `off` is how far outside the range it is, negative
     * when short, 0 when inside.
     *
     * @return array{count: int, unit: string, min: int, max: int, off: int}
     */
    public static function lengthOf(string $body, ?string $language): array
    {
        $text = preg_replace('/^#{1,6}\s*(出典|出处|出處|Sources?|Quellen)\b.*\z/imsu', '', $body) ?? $body;
        $text = preg_replace('/^#{1,6}\s*|\[([^\]]*)\]\([^)]*\)|[*_`>]/mu', '$1', $text) ?? $text;
        $isCjk = $language === null ? preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $text) === 1 : in_array($language, ['ja', 'zh-Hans', 'zh-Hant'], true);
        $unit = $isCjk ? 'characters' : 'words';
        $count = $unit === 'characters'
            ? mb_strlen(preg_replace('/\s+/u', '', $text) ?? $text)
            : count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        [$min, $max] = self::LENGTHS[$unit];

        return ['count' => $count, 'unit' => $unit, 'min' => $min, 'max' => $max, 'off' => $count < $min ? $count - $min : max(0, $count - $max)];
    }
}
