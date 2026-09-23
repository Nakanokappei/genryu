<?php

use App\Actions\ProposeHeadline;
use App\Actions\ScoreHeadline;
use App\Jobs\RefineHeadline;
use App\Jobs\TranslateArticle;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const HEADLINE_POLICY = "見出しを採点し、通らなければ書き直す。\n";

/**
 * What the judge would answer, as the Responses API wire format: every
 * must passed and every item at the score given, so a test can ask for a
 * total by asking for the scores that make it.
 */
function headlineScore(int $common, int $optional = 0, array $overrides = [], array $mustsFailed = []): array
{
    $json = [
        'musts' => array_map(fn (string $key): bool => ! in_array($key, $mustsFailed, true), array_combine(array_keys(ScoreHeadline::MUSTS), array_keys(ScoreHeadline::MUSTS))),
        'common' => array_map(fn (array $item): int => min($item['points'], $common), ScoreHeadline::COMMON),
        'optional' => array_fill_keys(array_keys(ScoreHeadline::OPTIONAL), $optional),
        'what_to_fix' => '記事にしかない事実を中心に置く',
        ...$overrides,
    ];

    return [
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]]],
        'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 800, 'cache_write_tokens' => 0], 'output_tokens' => 200],
    ];
}

/** What the rewriter would answer. */
function headlineProposal(string $headline): array
{
    return [
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['headline' => $headline], JSON_UNESCAPED_UNICODE)]]]],
        'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 800, 'cache_write_tokens' => 0], 'output_tokens' => 100],
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.openai.key' => 'test-key']);
    EditorialPolicy::query()->create(['layer' => 'headline', 'body' => HEADLINE_POLICY, 'model' => 'gpt-5.6-luna']);
    $this->actingAs(User::factory()->create());
});

function refineHeadline(Article $article): Article
{
    $article = RefineHeadline::queueFor($article);
    (new RefineHeadline($article))->handle(app(ScoreHeadline::class), app(ProposeHeadline::class));

    return $article->refresh();
}

// The arithmetic is ours: eight common items plus the best two of the optional ones, and three conditions to pass.
it('adds up the score and decides the verdict itself', function () {
    $full = ScoreHeadline::review(json_decode((string) headlineScore(100, 10)['output'][0]['content'][0]['text'], true));

    expect($full['total'])->toBe(100)->and($full['passed'])->toBeTrue()
        ->and($full['counted'])->toHaveCount(ScoreHeadline::OPTIONAL_COUNTED);

    // Five points everywhere: five per common item, and the best two optional ones at five each.
    $five = 5 * count(ScoreHeadline::COMMON) + 5 * ScoreHeadline::OPTIONAL_COUNTED;
    $half = ScoreHeadline::review(json_decode((string) headlineScore(5, 5)['output'][0]['content'][0]['text'], true));
    expect($half['total'])->toBe($five)->and($half['passed'])->toBeFalse();

    // A must that failed is a rewrite whatever the total says.
    $failed = ScoreHeadline::review(json_decode((string) headlineScore(100, 10, mustsFailed: ['faithful'])['output'][0]['content'][0]['text'], true));
    expect($failed['total'])->toBe(100)->and($failed['passed'])->toBeFalse()->and($failed['musts_failed'])->toBe(['faithful']);

    // Specific to this article carries its own bar, even when everything else is full.
    $vague = ScoreHeadline::review(json_decode((string) headlineScore(100, 10, ['common' => [...array_map(fn (array $item): int => $item['points'], ScoreHeadline::COMMON), 'specific_to_this_article' => 5]])['output'][0]['content'][0]['text'], true));
    expect($vague['passed'])->toBeFalse();
});

// A headline that passes at once is kept, and the translations follow it.
it('keeps a headline that passes and queues the translations', function () {
    Http::fake(['api.openai.com/*' => Http::response(headlineScore(100, 10))]);
    $article = Article::factory()->create(['language' => 'ja', 'title' => 'ナフサ分解炉の炎は、メタンだけではない', 'body' => '## 本文']);

    $article = refineHeadline($article);

    expect($article->title)->toBe('ナフサ分解炉の炎は、メタンだけではない')
        ->and($article->headline_review['total'])->toBe(100)
        ->and($article->headline_review['passed'])->toBeTrue()
        ->and($article->headline_review['attempts'])->toHaveCount(1);
    expect(Http::recorded())->toHaveCount(1);
    Queue::assertPushed(TranslateArticle::class, 3);

    // The policy is the cached block, the rubric follows it, and the headline and the article come last.
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['input'][0]['content'][0]['text'] === HEADLINE_POLICY
            && isset($body['input'][0]['content'][0]['prompt_cache_breakpoint'])
            && str_contains($body['input'][1]['content'], 'specific_to_this_article (20)')
            && str_contains($body['input'][2]['content'], 'ナフサ分解炉の炎は、メタンだけではない');
    });
});

// One that does not pass is written again with the review in hand, and the better one is kept.
it('writes the headline again until it passes', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(headlineScore(5))
        ->push(headlineProposal('ナフサ分解炉の炎は、メタンだけではない'))
        ->push(headlineScore(100, 10))]);
    $article = Article::factory()->create(['language' => 'ja', 'title' => '燃料を替える取り組み', 'body' => '## 本文']);

    $article = refineHeadline($article);

    expect($article->title)->toBe('ナフサ分解炉の炎は、メタンだけではない')
        ->and($article->headline_review['passed'])->toBeTrue()
        // jsonb keeps no key order, so the attempts are compared by value.
        ->and($article->headline_review['attempts'])->toEqual([
            ['headline' => '燃料を替える取り組み', 'total' => 5 * count(ScoreHeadline::COMMON), 'passed' => false],
            ['headline' => 'ナフサ分解炉の炎は、メタンだけではない', 'total' => 100, 'passed' => true],
        ]);

    // The rewriter is given what fell short and what was tried, never a headline to imitate.
    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['input'][2]['content'] ?? ''), '燃料を替える取り組み')
        && str_contains((string) ($request->data()['input'][2]['content'] ?? ''), '記事にしかない事実を中心に置く'));
});

// Nothing passes: the loop stops at ATTEMPTS and keeps the best of what it saw.
it('stops after the last attempt and keeps the best', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(headlineScore(5))
        ->push(headlineProposal('二番目の見出し'))
        ->push(headlineScore(9))
        ->push(headlineProposal('三番目の見出し'))
        ->push(headlineScore(2))]);
    $article = Article::factory()->create(['language' => 'ja', 'title' => '最初の見出し', 'body' => '## 本文']);

    $article = refineHeadline($article);

    expect($article->title)->toBe('二番目の見出し')
        ->and($article->headline_review['passed'])->toBeFalse()
        ->and($article->headline_review['attempts'])->toHaveCount(RefineHeadline::ATTEMPTS);
    Queue::assertPushed(TranslateArticle::class, 3);
});

// A headline the loop could not judge leaves the article with the one it had.
it('leaves the article its headline when the loop fails', function () {
    Http::fake(['api.openai.com/*' => Http::response(['output' => []])]);
    $article = Article::factory()->create(['language' => 'ja', 'title' => '最初の見出し', 'body' => '## 本文']);

    $article = refineHeadline($article);

    expect($article->title)->toBe('最初の見出し')->and($article->headline_review)->toHaveKey('error');
    Queue::assertPushed(TranslateArticle::class, 3);
});
