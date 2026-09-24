<?php

use App\Actions\DrawSpotCheck;
use App\Jobs\TranslateSpotCheck;
use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Models\EditorialPolicy;
use App\Models\SemanticFilterExample;
use App\Models\SpotCheck;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.openai.key' => 'test-key']);
    EditorialPolicy::query()->create(['layer' => 'semantic_filter', 'body' => '', 'model' => 'text-embedding-3-large', 'threshold' => 0.10]);
    $this->actingAs(User::factory()->create());
});

/** A document the semantic filter measured at a given likeness, embedded at a given time. */
function measured(float $likeness, string $embeddedAt = '2026-09-24 03:00:00'): Document
{
    $document = Document::factory()->fetched()->create(['likeness' => $likeness]);
    $embedding = DocumentEmbedding::query()->create(['document_id' => $document->id, 'model' => 'text-embedding-3-large', 'vector' => [1.0]]);
    $embedding->forceFill(['created_at' => $embeddedAt])->save();

    return $document;
}

// The day's draw: 3 that passed, 4 just below the threshold, 3 far below, each weighted by the size of its stratum.
it('draws a stratified sample of the day the semantic filter measured', function () {
    foreach (range(1, 6) as $i) {
        measured(0.15);
    }
    foreach (range(1, 2) as $i) {
        measured(0.05);
    }
    foreach (range(1, 9) as $i) {
        measured(-0.20);
    }
    measured(0.30, '2026-09-23 03:00:00');
    Document::factory()->fetched()->create(['likeness' => 0.3, 'excluded_by' => 'rule']);
    // An example of the semantic filter has been judged already.
    SemanticFilterExample::query()->create(['document_id' => measured(0.15)->id, 'side' => 'unlike']);

    $result = app(DrawSpotCheck::class)(CarbonImmutable::parse('2026-09-24'));

    expect($result)->toBe(['drawn' => 8, 'population' => 17, 'already' => false])
        ->and(SpotCheck::query()->where('stratum', 'passed')->pluck('weight')->unique()->all())->toBe([2.0])
        ->and(SpotCheck::query()->where('stratum', 'near')->count())->toBe(2)
        ->and(SpotCheck::query()->where('stratum', 'near')->value('weight'))->toBe(1.0)
        ->and(SpotCheck::query()->where('stratum', 'far')->value('weight'))->toBe(3.0)
        ->and(SpotCheck::query()->where('stratum', 'far')->value('passed'))->toBeFalse()
        ->and(SpotCheck::query()->value('threshold'))->toBe(0.1);
    Queue::assertPushed(TranslateSpotCheck::class, 8);
    // A day is drawn once.
    expect(app(DrawSpotCheck::class)(CarbonImmutable::parse('2026-09-24'))['already'])->toBeTrue();
});

it('puts the title and gist into Japanese, and keeps a failure on the row', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['title' => '英国での LLM の利用と信頼', 'summary' => '英国の成人を調べた。'], JSON_UNESCAPED_UNICODE)]]]]])]);
    $check = SpotCheck::query()->create(['document_id' => measured(0.2)->id, 'drawn_on' => '2026-09-24', 'stratum' => 'passed', 'weight' => 1, 'likeness' => 0.2, 'threshold' => 0.1, 'passed' => true]);

    (new TranslateSpotCheck($check))->handle();

    expect($check->refresh())->toMatchArray(['title_ja' => '英国での LLM の利用と信頼', 'summary_ja' => '英国の成人を調べた。', 'translation_error' => null]);
});

// One at a time, the likeness hidden until judged; a verdict moves on to the next one not judged. It is not an example and not a verdict on the document.
it('takes a verdict on each drawn document and shows the likeness only once judged', function () {
    $first = SpotCheck::query()->create(['document_id' => measured(0.2)->id, 'drawn_on' => '2026-09-24', 'stratum' => 'passed', 'weight' => 1, 'likeness' => 0.2346, 'threshold' => 0.1, 'passed' => true, 'title_ja' => '一つ目']);
    $second = SpotCheck::query()->create(['document_id' => measured(-0.3)->id, 'drawn_on' => '2026-09-24', 'stratum' => 'far', 'weight' => 1, 'likeness' => -0.3, 'threshold' => 0.1, 'passed' => false, 'title_ja' => '二つ目']);

    $page = Livewire::test('pages::supervision.spot-checks.index')
        ->assertSet('check', $first->id)->assertSee('一つ目')->assertDontSee('+0.235')
        ->call('decide', 'like')
        ->assertSet('check', $second->id);
    // The next one is in view, not only chosen: the document shown is not the one held over from before the verdict.
    expect($page->instance()->current->id)->toBe($second->id);

    expect($first->refresh())->toMatchArray(['verdict' => 'like'])->and($first->decided_by)->toBe(auth()->id());
    $page->call('move', -1)->assertSee('+0.235');
    expect($first->document->refresh()->human_decision)->toBeNull()->and($first->document->semanticFilterExample)->toBeNull();

    $this->get(route('supervision.spot-checks.index'))->assertOk()->assertSee('抜き取り点検');
});

// Once every one is judged the day is closed with 確定; a closed day takes no verdict until it is reopened.
it('confirms a day once every document of it is judged, and reopens it', function () {
    $first = SpotCheck::query()->create(['document_id' => measured(0.2)->id, 'drawn_on' => '2026-09-24', 'stratum' => 'passed', 'weight' => 1, 'likeness' => 0.2, 'threshold' => 0.1, 'passed' => true, 'title_ja' => '一つ目']);
    $second = SpotCheck::query()->create(['document_id' => measured(-0.3)->id, 'drawn_on' => '2026-09-24', 'stratum' => 'far', 'weight' => 1, 'likeness' => -0.3, 'threshold' => 0.1, 'passed' => false, 'title_ja' => '二つ目']);

    $page = Livewire::test('pages::supervision.spot-checks.index')
        ->call('confirm')
        ->call('decide', 'like')
        ->call('decide', 'unlike')
        // After the last one, the confirmation rather than the last page.
        ->assertSet('check', null)->assertSee('この日の文書をすべて判定しました');
    expect($first->refresh()->confirmed_at)->toBeNull();

    $page->call('confirm')->assertSee('この日の点検は確定済みです');
    expect($first->refresh()->confirmed_at)->not->toBeNull()->and($second->refresh()->confirmed_by)->toBe(auth()->id());

    // Closed: a verdict does nothing until the day is reopened.
    $page->call('show', $first->id)->call('decide', 'unlike');
    expect($first->refresh()->verdict)->toBe('like');
    $page->call('reopen')->call('show', $first->id)->call('decide', 'unlike');
    expect($first->refresh())->toMatchArray(['verdict' => 'unlike', 'confirmed_at' => null]);
});
