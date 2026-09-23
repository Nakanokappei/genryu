<?php

namespace App\Actions;

use App\Models\Article;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The judge of 見出し (the headline): given the headline layer of the
 * editorial policy, a headline and the article under it, a model scores
 * the headline item by item and says what a better one would have to do.
 *
 * The model scores; it does not decide. The weights, the arithmetic and
 * the verdict are here, in PHP, so that two runs of the same rubric are
 * comparable and a model cannot pass itself by adding up wrongly. Three
 * layers: the musts, which are pass or fail; eight common items worth 80
 * between them; and eleven optional ones worth 10 each, of which only the
 * best two count, because an article does not have to carry all of them.
 * A headline passes when nothing failed, the total is at least
 * PASS_TOTAL, and it is at least half specific to this article.
 */
class ScoreHeadline
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    private const MAX_BODY_CHARS = 20000;

    /**
     * Fail any of these and the headline is rewritten whatever it scored.
     * Whether a forecast is written as a fact was one of these until
     * 2026-09-23: it is too strong a test for a headline, which has no
     * room to qualify, and belongs to the body instead.
     */
    public const MUSTS = [
        'topic_word_present' => 'The headline carries a topic word that belongs to what the article is about.',
        'word_is_common' => 'That topic word is one the intended reader knows.',
        'read_at_once' => 'The headline can be taken in on one reading.',
        'faithful' => 'The article answers what the headline claims and promises.',
    ];

    /**
     * Every headline is scored on all six; 80 between them. There were
     * eight until 2026-09-23: asking one short line to carry why it has
     * to be known now and what is lost by not knowing as well set a bar
     * no headline reached (the best of five articles scored 64 to 77),
     * so those two moved to the optional items, where a headline may
     * take them or leave them, and their points were spread over the
     * rest.
     */
    public const COMMON = [
        'specific_to_this_article' => ['points' => 20, 'about' => 'Only this article could carry this headline: a fact, a finding or a cause from it is at the centre.'],
        'about_the_reader' => ['points' => 12, 'about' => 'What it does to the reader\'s work, life, money or time is visible.'],
        'curiosity' => ['points' => 12, 'about' => 'The subject is clear, and the reason or the mechanism is worth opening the article for.'],
        'concreteness' => ['points' => 12, 'about' => 'An event, a change or a scale comes through, rather than an abstraction.'],
        'single_focus' => ['points' => 12, 'about' => 'One claim, not several.'],
        'latent_question' => ['points' => 12, 'about' => 'It puts into words a doubt the reader half felt already.'],
    ];

    /** Scored the same way, but only the best two are added: a headline need not carry them all. */
    public const OPTIONAL = [
        'social_problem_solved' => 'It shows how a problem of society gets solved.',
        'impossible_made_possible' => 'What could not be done becomes possible.',
        'why_the_strong_lose' => 'It shows why those who are strong today lose.',
        'how_the_weak_win' => 'It shows how those who are weak today win.',
        'common_sense_reversed' => 'What is taken for granted stops being true.',
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

    /** What the model is told after the cached policy: the rubric, and that it scores rather than decides. Shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'Score the headline below against every item, on the article as written rather than on what the headline promises. Score 0 when an item is not met, half its points when it is partly met, and its full points when it is met. An angle that is only the same thing said again does not score twice among the optional items. Add nothing up: the totals and the verdict are worked out from your scores. In what_to_fix, say in one or two lines what a better headline would have to do — never write the headline itself.';

    /**
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, string $headline, Article $article): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($policy, $model, $headline, $article))
            ->throw();

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $json = json_decode(self::outputText($response->json()), true);

        if (! is_array($json)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
        }

        return [
            'json' => $json,
            'usage' => [
                'input_tokens' => self::count($response->json('usage.input_tokens')),
                'cached_tokens' => self::count($response->json('usage.input_tokens_details.cached_tokens')),
                'cache_write_tokens' => self::count($response->json('usage.input_tokens_details.cache_write_tokens')),
                'output_tokens' => self::count($response->json('usage.output_tokens')),
                'latency_ms' => $latency,
            ],
        ];
    }

    /**
     * The request: the policy first, as the developer message, with the
     * cache breakpoint on it; the rubric after it; the headline and the
     * article it heads last.
     *
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, string $headline, Article $article): array
    {
        return [
            'model' => $model,
            'prompt_cache_options' => ['mode' => 'explicit'],
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        ['type' => 'input_text', 'text' => $policy, 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
                    ],
                ],
                ['role' => 'developer', 'content' => self::INSTRUCTIONS."\n\n".self::rubric()],
                ['role' => 'user', 'content' => "Headline:\n{$headline}\n\nArticle:\n".mb_substr((string) $article->body, 0, self::MAX_BODY_CHARS)],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'headline_score',
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
        ];
    }

    /** The rubric as the model reads it: the three layers with their points. */
    public static function rubric(): string
    {
        $lines = ['Must pass, or the headline is rewritten whatever it scores:'];

        foreach (self::MUSTS as $key => $about) {
            $lines[] = "- {$key}: {$about}";
        }

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
     * optional ones — and the verdict from the three conditions.
     *
     * @param  array<string, mixed>  $json  what the model answered
     * @return array<string, mixed>
     */
    public static function review(array $json): array
    {
        $failed = [];

        foreach (array_keys(self::MUSTS) as $key) {
            if (($json['musts'][$key] ?? false) !== true) {
                $failed[] = $key;
            }
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
            'passed' => $failed === [] && $total >= self::PASS_TOTAL && $common['specific_to_this_article'] >= self::PASS_SPECIFIC,
            'what_to_fix' => trim((string) ($json['what_to_fix'] ?? '')),
        ];
    }

    /**
     * The text of the answer: the output_text of the first message in
     * the output (reasoning items and the like are passed over).
     *
     * @param  array<string, mixed>|null  $body
     */
    private static function outputText(?array $body): string
    {
        foreach ($body['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    return (string) $content['text'];
                }
            }
        }

        return (string) ($body['output_text'] ?? '');
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
