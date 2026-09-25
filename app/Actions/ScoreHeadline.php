<?php

namespace App\Actions;

use App\Models\LanguageSetting;
use App\OpenAi\Responses;
use App\Support\Hedges;

/**
 * The judge of 見出し (headline): a model scores a headline item by item on
 * the material; the weights, total and verdict are worked out here.
 */
class ScoreHeadline
{
    /** Pass-or-fail items; failing any fails the headline. */
    public const MUSTS = [
        'topic_word_present' => 'The headline carries a topic word that belongs to what the article is about.',
        'topic_word_names_the_field' => 'That topic word is the field\'s own big noun, naming the world the article steps into — not a general word such as technology, AI or research, and not a specialist\'s narrow term such as a model number. It need not be a word the reader already uses.',
        'read_at_once' => 'The headline can be taken in on one reading.',
        'faithful' => 'The material supports what the headline claims and promises.',
    ];

    /** Length limit, counted in code: characters in Chinese, Japanese or Korean, words otherwise. */
    public const MAX_CHARACTERS = 30;

    public const MAX_WORDS = 14;

    /** Items scored on every headline, with their points (80 in all). */
    public const COMMON = [
        'specific_to_this_article' => ['points' => 20, 'about' => 'Only an article from this material could carry this headline: a fact, a finding or a cause from it is at the centre.'],
        'common_sense_reversed' => ['points' => 15, 'about' => 'What the reader takes for granted stops being true: the headline denies an assumption, or shows it looking in the wrong place, rather than summarising the news.'],
        'about_the_reader' => ['points' => 8, 'about' => 'What it does to the reader\'s work, life, money or time is visible.'],
        'curiosity' => ['points' => 8, 'about' => 'The subject is clear, and the reason or the mechanism is worth opening the article for.'],
        'concreteness' => ['points' => 8, 'about' => 'An event, a change or a scale comes through, rather than an abstraction.'],
        'single_focus' => ['points' => 7, 'about' => 'One claim, not several.'],
        'latent_question' => ['points' => 7, 'about' => 'It puts into words a doubt the reader half felt already.'],
        'what_this_makes_possible' => ['points' => 7, 'about' => 'It points to what this announcement makes possible, not to what still stands in the way: the step this news takes, not a distant future the material cannot vouch for.'],
    ];

    /** Items worth 10 each, of which only the best OPTIONAL_COUNTED are added. */
    public const OPTIONAL = [
        'social_problem_solved' => 'It shows how a problem of society gets solved.',
        'impossible_made_possible' => 'What could not be done becomes possible.',
        'why_the_strong_lose' => 'It shows why those who are strong today lose.',
        'how_the_weak_win' => 'It shows how those who are weak today win.',
        'unthinkable_becomes_normal' => 'What is unthinkable today becomes ordinary.',
        'surprising_proper_noun' => 'A name everyone knows makes the whole headline unexpected.',
        'surprising_causality' => 'A cause and an effect are joined in a way nobody would have guessed.',
        'conflict' => 'Values that cannot both be had, or interests that collide, are visible.',
        'cost_or_limit' => 'The sacrifice behind the gain, or the unexpected condition of the success, is visible.',
        'unlikely_words_joined' => 'Words that do not usually go together are joined, and mean something.',
        'gain_or_loss' => 'What is gained by reading, or lost by not knowing, is visible.',
        'why_now' => 'Why this has to be known now.',
    ];

    /** How many of the optional items are added to the total. */
    public const OPTIONAL_COUNTED = 2;

    /** Minimum total to pass. */
    public const PASS_TOTAL = 70;

    /** Minimum score for specific_to_this_article. */
    public const PASS_SPECIFIC = 10;

    /** Minimum score for common_sense_reversed. */
    public const PASS_REVERSED = 12;

    /** Fixed instruction sent after the cached policy; shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'Score the headline below against every item, on the material rather than on what the headline promises. Score 0 when an item is not met, half its points when it is partly met, and its full points when it is met. An angle that is only the same thing said again does not score twice among the optional items. Add nothing up: the totals and the verdict are worked out from your scores. In what_to_fix, say in one or two lines what a better headline would have to do — never write the headline itself.';

    /**
     * @param  array<string, mixed>  $material
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, string $headline, array $material, ?string $language = null): array
    {
        return Responses::send(self::request($policy, $model, $headline, $material, $language));
    }

    /**
     * The request: cached policy, language prompts, rubric, then headline and material.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, string $headline, array $material, ?string $language = null): array
    {
        return Responses::request($model, [
            Responses::policy($policy),
            // Language rules, so the judge does not mark a headline down for following them.
            ...LanguageSetting::messages($language),
            ['role' => 'developer', 'content' => self::INSTRUCTIONS."\n\n".self::rubric()],
            ['role' => 'user', 'content' => "Headline:\n{$headline}\n\nMaterial (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ], 'headline_score', self::schema());
    }

    /** The rubric as text for the model. */
    public static function rubric(): string
    {
        $lines = ['Must pass, or the headline is rewritten whatever it scores:'];

        foreach (self::MUSTS as $key => $about) {
            $lines[] = "- {$key}: {$about}";
        }

        $lines[] = '- short_enough (counted by code, not scored): at most '.self::MAX_CHARACTERS.' characters in Chinese, Japanese or Korean, at most '.self::MAX_WORDS.' words in any other language.';
        $lines[] = '- unhedged (found by code, not scored): no hedge — may, might, could, possibly, perhaps, 可能性, かもしれない, ことがある and the like. State what the material vouches for; the caveats belong in the body.';

        $lines[] = '';
        $lines[] = 'Scored on every headline, 80 points between them:';

        foreach (self::COMMON as $key => $item) {
            $lines[] = "- {$key} ({$item['points']}): {$item['about']}";
        }

        $lines[] = '';
        $lines[] = 'Scored out of 10 each; only the best '.self::OPTIONAL_COUNTED.' are added:';

        foreach (self::OPTIONAL as $key => $about) {
            $lines[] = "- {$key} (10): {$about}";
        }

        return implode("\n", $lines);
    }

    /**
     * The answer schema: musts, scores and what_to_fix; no totals.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $object = fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $score = ['type' => 'integer'];

        return $object([
            'musts' => $object(array_fill_keys(array_keys(self::MUSTS), ['type' => 'boolean'])),
            'common' => $object(array_fill_keys(array_keys(self::COMMON), $score)),
            'optional' => $object(array_fill_keys(array_keys(self::OPTIONAL), $score)),
            'what_to_fix' => ['type' => 'string'],
        ]);
    }

    /**
     * The stored review: scores clamped, the total and the verdict.
     *
     * @param  array<string, mixed>  $json  what the model answered
     * @return array<string, mixed>
     */
    public static function review(array $json, string $headline): array
    {
        $failed = [];

        foreach (array_keys(self::MUSTS) as $key) {
            if (($json['musts'][$key] ?? false) !== true) {
                $failed[] = $key;
            }
        }

        // Length is counted in code; a model misjudges it.
        if (! self::isShortEnough($headline)) {
            $failed[] = 'short_enough';
        }

        // Hedges are found in code as well: the headline states what the material vouches for.
        if (Hedges::count($headline) > 0) {
            $failed[] = 'unhedged';
        }

        $common = [];

        foreach (self::COMMON as $key => $item) {
            $common[$key] = max(0, min($item['points'], (int) ($json['common'][$key] ?? 0)));
        }

        $optional = [];

        foreach (array_keys(self::OPTIONAL) as $key) {
            $optional[$key] = max(0, min(10, (int) ($json['optional'][$key] ?? 0)));
        }

        // Only the best optional items count.
        $counted = collect($optional)->sortDesc()->take(self::OPTIONAL_COUNTED);
        $total = array_sum($common) + $counted->sum();

        return [
            'musts_failed' => $failed,
            'common' => $common,
            'optional' => $optional,
            'counted' => $counted->keys()->all(),
            'total' => $total,
            'passed' => $failed === [] && $total >= self::PASS_TOTAL && $common['specific_to_this_article'] >= self::PASS_SPECIFIC
                && $common['common_sense_reversed'] >= self::PASS_REVERSED,
            'what_to_fix' => trim((string) ($json['what_to_fix'] ?? '')),
        ];
    }

    /** Whether a headline fits MAX_CHARACTERS (Han, kana, Hangul) or MAX_WORDS (others). */
    public static function isShortEnough(string $headline): bool
    {
        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $headline) === 1) {
            return mb_strlen(trim($headline)) <= self::MAX_CHARACTERS;
        }

        return count(preg_split('/\s+/u', trim($headline), -1, PREG_SPLIT_NO_EMPTY) ?: []) <= self::MAX_WORDS;
    }
}
