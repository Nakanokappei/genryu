<?php

namespace App\Actions;

use App\Models\LanguageSetting;
use App\OpenAi\Responses;

/**
 * The judge of 見出し (the headline): given the headline layer of the
 * editorial policy, a headline and the material its article will be
 * written from, a model scores the headline item by item and says what a
 * better one would have to do. The headline comes before the body, so it
 * is judged on what the material can deliver, and the body is then
 * written to deliver it.
 *
 * The model scores; it does not decide. The weights, the arithmetic and
 * the verdict are here, in PHP, so that two runs of the same rubric are
 * comparable and a model cannot pass itself by adding up wrongly. Three
 * layers: the musts, which are pass or fail; eight common items worth 80
 * between them; and twelve optional ones worth 10 each, of which only the
 * best two count, because an article does not have to carry all of them.
 * A headline passes when nothing failed, the total is at least
 * PASS_TOTAL, and it is at least half specific to this article and at
 * least a clear reversal of what the reader takes for granted.
 */
class ScoreHeadline
{
    /**
     * Fail any of these and the headline is rewritten whatever it scored.
     * Whether a forecast is written as a fact was one of these until
     * 2026-09-23: it is too strong a test for a headline, which has no
     * room to qualify, and belongs to the body instead.
     */
    public const MUSTS = [
        'topic_word_present' => 'The headline carries a topic word that belongs to what the article is about.',
        // Until 2026-09-23 this asked for a word the reader already knows, which fought the policy's "the field's own big noun" and failed デジタルツイン and 分解炉; the body's second part explains the word, so the headline may carry one the reader has yet to learn.
        'topic_word_names_the_field' => 'That topic word is the field\'s own big noun, naming the world the article steps into — not a general word such as technology, AI or research, and not a specialist\'s narrow term such as a model number. It need not be a word the reader already uses.',
        'read_at_once' => 'The headline can be taken in on one reading.',
        'faithful' => 'The material supports what the headline claims and promises.',
    ];

    /**
     * The one must counted here rather than judged by the model: a
     * headline in Chinese, Japanese or Korean takes at most MAX_CHARACTERS
     * characters, one in any other language at most MAX_WORDS words.
     * Added 2026-09-23, when a headline of 36 characters passed on 82, at
     * 25 characters; raised to 30 the same day, because a line that
     * denies an assumption needs room for the contrast.
     */
    public const MAX_CHARACTERS = 30;

    public const MAX_WORDS = 14;

    /**
     * Every headline is scored on all six; 80 between them. There were
     * eight until 2026-09-23: asking one short line to carry why it has
     * to be known now and what is lost by not knowing as well set a bar
     * no headline reached (the best of five articles scored 64 to 77),
     * so those two moved to the optional items, where a headline may
     * take them or leave them, and their points were spread over the
     * rest. The direction of a headline was a must for one run on
     * 2026-09-23 and pushed the writer to claim a distant future the
     * material could not vouch for, so it is scored here instead, as what
     * this announcement makes possible. The reversal of what the reader
     * takes for granted — what the headline is for — was one optional
     * item among thirteen until 2026-09-23, when headlines that only
     * summarised the news passed at once; it is scored on every headline
     * now and carries a bar of its own (PASS_REVERSED).
     */
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

    /** Scored the same way, but only the best two are added: a headline need not carry them all. */
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

    /**
     * What a headline has to reach to be kept. 80 until 2026-09-23, which
     * nothing reached once the headline also had to be one short line;
     * the bar only decides when the loop stops trying, so a bar nothing
     * clears costs the full three attempts on every article.
     */
    public const PASS_TOTAL = 70;

    /** And it has to be at least half specific to this article, whatever else it scores. */
    public const PASS_SPECIFIC = 10;

    /**
     * And a clear reversal of what the reader takes for granted: a
     * headline that only summarises does not pass. Half the 15 (8) let a
     * paraphrase through on 2026-09-23 (熟練者の操作を教材に変える), so the
     * bar is 12.
     */
    public const PASS_REVERSED = 12;

    /** What the model is told after the cached policy: the rubric, and that it scores rather than decides. Shown on the screen under the prompt. */
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
     * The request: the policy first, as the developer message, with the
     * cache breakpoint on it; the rubric after it; the headline and the
     * material its article will be written from last.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, string $headline, array $material, ?string $language = null): array
    {
        return Responses::request($model, [
            Responses::policy($policy),
            // The judge knows the rules of the language too, so it does not score a headline down for keeping them.
            ...LanguageSetting::messages($language),
            ['role' => 'developer', 'content' => self::INSTRUCTIONS."\n\n".self::rubric()],
            ['role' => 'user', 'content' => "Headline:\n{$headline}\n\nMaterial (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ], 'headline_score', self::schema());
    }

    /** The rubric as the model reads it: the three layers with their points. */
    public static function rubric(): string
    {
        $lines = ['Must pass, or the headline is rewritten whatever it scores:'];

        foreach (self::MUSTS as $key => $about) {
            $lines[] = "- {$key}: {$about}";
        }

        $lines[] = '- short_enough (counted by code, not scored): at most '.self::MAX_CHARACTERS.' characters in Chinese, Japanese or Korean, at most '.self::MAX_WORDS.' words in any other language.';

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
     * The schema: whether each must passed, a score for every item, and
     * what a better headline would have to do. No totals — those are ours.
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
     * The review as it is kept: every score held to its ceiling, the
     * total worked out here — the eight common items plus the best two
     * optional ones — and the verdict from the four conditions.
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

        // The length is counted, not judged: a model reads a long line as short enough.
        if (! self::isShortEnough($headline)) {
            $failed[] = 'short_enough';
        }

        $common = [];

        foreach (self::COMMON as $key => $item) {
            $common[$key] = max(0, min($item['points'], (int) ($json['common'][$key] ?? 0)));
        }

        $optional = [];

        foreach (array_keys(self::OPTIONAL) as $key) {
            $optional[$key] = max(0, min(10, (int) ($json['optional'][$key] ?? 0)));
        }

        // Only the best few of the optional items count, so an article is never asked to carry all of them.
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

    /**
     * Whether a headline fits its length: characters for a headline
     * written in Han, kana or Hangul, words for any other.
     */
    public static function isShortEnough(string $headline): bool
    {
        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $headline) === 1) {
            return mb_strlen(trim($headline)) <= self::MAX_CHARACTERS;
        }

        return count(preg_split('/\s+/u', trim($headline), -1, PREG_SPLIT_NO_EMPTY) ?: []) <= self::MAX_WORDS;
    }
}
