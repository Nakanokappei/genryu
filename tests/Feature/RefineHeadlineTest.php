<?php

use App\Actions\ProposeHeadline;
use App\Actions\ScoreHeadline;
use App\Jobs\GenerateArticle;
use App\Jobs\RefineHeadline;
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

/** What the writer would answer: the four steps, of which only the headline is kept. */
function headlineProposal(string $headline): array
{
    $json = ['topic_word' => 'ナフサ分解炉', 'title_draft' => 'ナフサ分解炉をアンモニアで動かした', 'assumption' => 'ナフサ分解炉はメタンで燃やすものだ', 'headline' => $headline];

    return [
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]]],
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

/** An article about to be written: no headline, no body, a material to write them from. */
function articleToWrite(): Article
{
    return Article::factory()->create(['status' => 'generating', 'title' => null, 'body' => null]);
}

function refineHeadline(Article $article): Article
{
    $article = RefineHeadline::queueFor($article);
    (new RefineHeadline($article))->handle(app(ScoreHeadline::class), app(ProposeHeadline::class));

    return $article->refresh();
}

// The arithmetic is ours: eight common items plus the best two of the optional ones, and four conditions to pass.
it('adds up the score and decides the verdict itself', function () {
    $full = ScoreHeadline::review(json_decode((string) headlineScore(100, 10)['output'][0]['content'][0]['text'], true), '短い見出し');

    expect($full['total'])->toBe(100)->and($full['passed'])->toBeTrue()
        ->and($full['counted'])->toHaveCount(ScoreHeadline::OPTIONAL_COUNTED);

    // Five points everywhere: five per common item, and the best two optional ones at five each.
    $five = 5 * count(ScoreHeadline::COMMON) + 5 * ScoreHeadline::OPTIONAL_COUNTED;
    $half = ScoreHeadline::review(json_decode((string) headlineScore(5, 5)['output'][0]['content'][0]['text'], true), '短い見出し');
    expect($half['total'])->toBe($five)->and($half['passed'])->toBeFalse();

    // A must that failed is a rewrite whatever the total says.
    $failed = ScoreHeadline::review(json_decode((string) headlineScore(100, 10, mustsFailed: ['faithful'])['output'][0]['content'][0]['text'], true), '短い見出し');
    expect($failed['total'])->toBe(100)->and($failed['passed'])->toBeFalse()->and($failed['musts_failed'])->toBe(['faithful']);

    // Specific to this article carries its own bar, even when everything else is full.
    $vague = ScoreHeadline::review(json_decode((string) headlineScore(100, 10, ['common' => [...array_map(fn (array $item): int => $item['points'], ScoreHeadline::COMMON), 'specific_to_this_article' => 5]])['output'][0]['content'][0]['text'], true), '短い見出し');
    expect($vague['passed'])->toBeFalse();

    // So does the reversal: a headline that only summarises does not pass, however well it scores elsewhere.
    $summary = ScoreHeadline::review(json_decode((string) headlineScore(100, 10, ['common' => [...array_map(fn (array $item): int => $item['points'], ScoreHeadline::COMMON), 'common_sense_reversed' => ScoreHeadline::PASS_REVERSED - 1]])['output'][0]['content'][0]['text'], true), '短い見出し');
    expect($summary['total'])->toBeGreaterThanOrEqual(ScoreHeadline::PASS_TOTAL)->and($summary['passed'])->toBeFalse();
});

// The length is counted in code: characters for Chinese or Japanese, words otherwise.
it('fails a headline that is too long whatever it scored', function () {
    $json = json_decode((string) headlineScore(100, 10)['output'][0]['content'][0]['text'], true);
    $long = ScoreHeadline::review($json, str_repeat('長', ScoreHeadline::MAX_CHARACTERS + 1));

    expect($long['passed'])->toBeFalse()->and($long['musts_failed'])->toBe(['short_enough'])
        ->and(ScoreHeadline::isShortEnough(str_repeat('長', ScoreHeadline::MAX_CHARACTERS)))->toBeTrue()
        ->and(ScoreHeadline::isShortEnough(implode(' ', array_fill(0, ScoreHeadline::MAX_WORDS, 'word'))))->toBeTrue()
        ->and(ScoreHeadline::isShortEnough(implode(' ', array_fill(0, ScoreHeadline::MAX_WORDS + 1, 'word'))))->toBeFalse();
});

// The first headline is written from the material; one that passes at once is kept, and the body follows it.
it('writes the headline from the material and keeps one that passes', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(headlineProposal('ナフサ分解炉の炎は、メタンだけではない'))
        ->push(headlineScore(100, 10))]);
    $article = articleToWrite();
    $article->material->update(['data' => ['angle' => 'ナフサ分解炉はアンモニアでも燃える']]);

    $article = refineHeadline($article);

    expect($article->title)->toBe('ナフサ分解炉の炎は、メタンだけではない')
        ->and($article->status)->toBe('generating')
        ->and($article->headline_review['total'])->toBe(100)
        ->and($article->headline_review['passed'])->toBeTrue()
        ->and($article->headline_review['attempts'])->toHaveCount(1);
    expect(Http::recorded())->toHaveCount(2);
    Queue::assertPushed(GenerateArticle::class, 1);

    // The writer walks the four steps and reads the material; the policy is the cached block, the rubric follows it.
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return ($body['text']['format']['name'] ?? '') === 'headline'
            && $body['input'][0]['content'][0]['text'] === HEADLINE_POLICY
            && isset($body['input'][0]['content'][0]['prompt_cache_breakpoint'])
            && $body['text']['format']['schema']['required'] === ['topic_word', 'title_draft', 'assumption', 'headline']
            && str_contains($body['input'][2]['content'], 'ナフサ分解炉はアンモニアでも燃える')
            && ! str_contains($body['input'][2]['content'], 'already tried');
    });

    // The judge scores the headline on the material, not on a body that does not exist yet.
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return ($body['text']['format']['name'] ?? '') === 'headline_score'
            && str_contains($body['input'][1]['content'], 'specific_to_this_article (20)')
            && str_contains($body['input'][2]['content'], 'ナフサ分解炉の炎は、メタンだけではない')
            && str_contains($body['input'][2]['content'], 'ナフサ分解炉はアンモニアでも燃える');
    });
});

// One that does not pass is written again with the review in hand, and the better one is kept.
it('writes the headline again until it passes', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(headlineProposal('燃料を替える取り組み'))
        ->push(headlineScore(5))
        ->push(headlineProposal('ナフサ分解炉の炎は、メタンだけではない'))
        ->push(headlineScore(100, 10))]);

    $article = refineHeadline(articleToWrite());

    expect($article->title)->toBe('ナフサ分解炉の炎は、メタンだけではない')
        ->and($article->headline_review['passed'])->toBeTrue()
        // jsonb keeps no key order, so the attempts are compared by value.
        ->and($article->headline_review['attempts'])->toEqual([
            ['headline' => '燃料を替える取り組み', 'total' => 5 * count(ScoreHeadline::COMMON), 'passed' => false, 'musts_failed' => []],
            ['headline' => 'ナフサ分解炉の炎は、メタンだけではない', 'total' => 100, 'passed' => true, 'musts_failed' => []],
        ]);

    // The rewriter is given what fell short and what was tried, never a headline to imitate.
    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['input'][2]['content'] ?? ''), '燃料を替える取り組み')
        && str_contains((string) ($request->data()['input'][2]['content'] ?? ''), '記事にしかない事実を中心に置く'));
});

// Nothing passes: the loop stops at ATTEMPTS and keeps the best of what it saw.
it('stops after the last attempt and keeps the best', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(headlineProposal('最初の見出し'))
        ->push(headlineScore(5))
        ->push(headlineProposal('二番目の見出し'))
        ->push(headlineScore(9))
        ->push(headlineProposal('三番目の見出し'))
        ->push(headlineScore(2))]);

    $article = refineHeadline(articleToWrite());

    expect($article->title)->toBe('二番目の見出し')
        ->and($article->headline_review['passed'])->toBeFalse()
        ->and($article->headline_review['attempts'])->toHaveCount(RefineHeadline::ATTEMPTS);
    expect(Http::recorded())->toHaveCount(2 * RefineHeadline::ATTEMPTS);
    Queue::assertPushed(GenerateArticle::class, 1);
});

// A headline that passed wins over a longer one that scored higher.
it('keeps the headline that passed over a higher total that was too long', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(headlineProposal(str_repeat('長', ScoreHeadline::MAX_CHARACTERS + 1)))
        ->push(headlineScore(100, 10))
        ->push(headlineProposal('ナフサ分解炉はアンモニアで燃える'))
        ->push(headlineScore(12, 5))]);

    $article = refineHeadline(articleToWrite());

    expect($article->title)->toBe('ナフサ分解炉はアンモニアで燃える')
        ->and($article->headline_review['passed'])->toBeTrue()
        // Each attempt keeps the musts it failed, so a person can see why a high total was passed over.
        ->and($article->headline_review['attempts'][0]['musts_failed'])->toBe(['short_enough']);
});

// There is no body without a headline: a loop that could not write one fails the article.
it('fails the article when no headline could be written', function () {
    Http::fake(['api.openai.com/*' => Http::response(['output' => []])]);

    $article = refineHeadline(articleToWrite());

    expect($article->status)->toBe('failed')->and($article->title)->toBeNull();
    Queue::assertNotPushed(GenerateArticle::class);
});
