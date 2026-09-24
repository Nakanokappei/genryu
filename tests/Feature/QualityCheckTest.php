<?php

use App\Actions\ScoreQuality;
use App\Jobs\CheckQuality;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\Models\QualityCheck;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const QUALITY_POLICY = "メディアの基準と採点基準で、記事を100点満点で採点する。\n";

/** What the judge would answer, as the Responses API wire format. */
function qualityAnswer(mixed $score, string $reason = '事実は素材情報どおりだが、結の条件が弱い。'): array
{
    return [
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['score' => $score, 'reason' => $reason], JSON_UNESCAPED_UNICODE)]]]],
        'usage' => ['input_tokens' => 3000, 'input_tokens_details' => ['cached_tokens' => 2000, 'cache_write_tokens' => 0], 'output_tokens' => 150],
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.openai.key' => 'test-key']);
    EditorialPolicy::query()->create(['layer' => 'quality', 'body' => QUALITY_POLICY, 'model' => 'gpt-5.6-luna']);
    $this->actingAs(User::factory()->create());
});

function checkQuality(Article $article): QualityCheck
{
    // Queued as the screens queue it, so the check pins the prompt version and the model.
    $check = CheckQuality::queueFor($article);
    (new CheckQuality($check))->handle(app(ScoreQuality::class));

    return $check->refresh();
}

// The judge scores the article against the policy, which carries the rubric, with the material to check the facts against.
it('scores a written article against the quality layer of the editorial policy', function () {
    Http::fake(['api.openai.com/*' => Http::response(qualityAnswer(78))]);
    $article = Article::factory()->create(['language' => 'ja', 'headline' => '工業炉の炎はアンモニアでも燃える', 'body' => "リード。\n\n## 出典\n\n[NEDO](https://www.nedo.go.jp/)"]);
    $article->material->update(['parts' => ['angle' => 'アンモニアは主燃料になった', 'facts' => ['混焼率は 85％']]]);

    $check = checkQuality($article);

    expect($check->status)->toBe('checked')
        ->and($check->score)->toBe(78)
        ->and($check->reason)->toBe('事実は素材情報どおりだが、結の条件が弱い。')
        ->and($check->prompt->layer)->toBe('quality')
        ->and($check->model)->toBe('gpt-5.6-luna')
        ->and($check)->toMatchArray(['input_tokens' => 3000, 'cached_tokens' => 2000, 'output_tokens' => 150])
        ->and($article->qualityCheck->is($check))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request['input'][0]['content'][0]['text'] === QUALITY_POLICY
        && isset($request['input'][0]['content'][0]['prompt_cache_breakpoint'])
        && str_contains($request['input'][2]['content'], '# 工業炉の炎はアンモニアでも燃える')
        && str_contains($request['input'][2]['content'], '混焼率は 85％')
        && $request['text']['format']['schema']['required'] === ['score', 'reason']);

    // The list shows the state and the score, with the reason on hover.
    $this->get(route('production.quality.index'))->assertSee('品質チェック')->assertSee('工業炉の炎はアンモニアでも燃える')->assertSee('チェック済み')->assertSee('78')->assertSee('結の条件が弱い');
});

// The rubric is out of 100 whatever the model adds up to, and an answer without a score is a failure.
it('holds the score to 100 and fails without one', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()->push(qualityAnswer(130))->push(qualityAnswer('good'))]);

    expect(checkQuality(Article::factory()->create())->score)->toBe(100);

    $failed = checkQuality(Article::factory()->create());
    expect($failed->status)->toBe('failed')->and($failed->status_message)->toContain('点数');
});

// A translation says what its original says: only the original is checked.
it('does not check a translation', function () {
    $original = Article::factory()->create();
    $translation = Article::factory()->for($original->material)->create(['translated_from_id' => $original->id, 'language' => 'en']);

    expect(checkQuality($translation)->status)->toBe('failed');
    Http::assertNothingSent();
});

// Checking follows writing on its own; the screen queues the unchecked and the failed, or all of them again.
it('queues the checks from the screen and shows those in progress', function () {
    $unchecked = Article::factory()->create();
    $failed = Article::factory()->create();
    $failed->qualityChecks()->create(['prompt_id' => Prompt::current('quality', QUALITY_POLICY)->id, 'model' => 'gpt-5.6-luna', 'status' => 'failed']);
    $checked = Article::factory()->create();
    $checked->qualityChecks()->create(['prompt_id' => Prompt::current('quality', QUALITY_POLICY)->id, 'model' => 'gpt-5.6-luna', 'status' => 'checked', 'score' => 90]);
    Article::factory()->for($unchecked->material)->create(['translated_from_id' => $unchecked->id, 'language' => 'en']);

    Livewire::test('pages::production.quality.index')->call('check');
    Queue::assertPushed(CheckQuality::class, 2);

    Livewire::test('pages::production.quality.index')->call('checkAll');
    Queue::assertPushed(CheckQuality::class, 5);

    $this->get(route('production.quality.index'))->assertSee('チェック中');
});

it('keeps the quality layer on the quality check screen, empty until it is saved', function () {
    EditorialPolicy::query()->delete();
    // No prompt in the repository (prompts are assets): nothing until the screen saves one.
    expect(EditorialPolicy::bodyFor('quality'))->toBe('');

    Livewire::test('pages::production.quality.index')
        ->assertSet('quality', '')
        ->set('quality', '正確さ 50 点、読みやすさ 50 点')
        ->set('qualityModel', 'gpt-5.6-terra')
        ->call('savePolicy')->assertHasNoErrors();

    expect(EditorialPolicy::bodyFor('quality'))->toBe('正確さ 50 点、読みやすさ 50 点')
        ->and(EditorialPolicy::modelFor('quality'))->toBe('gpt-5.6-terra');
});
