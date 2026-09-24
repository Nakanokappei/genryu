<?php

use App\Actions\FetchFavicon;
use App\Actions\MeasureLikeness;
use App\Actions\ProposeDecision;
use App\Actions\ProposeDocumentSettings;
use App\Actions\ReadDocument;
use App\Actions\ReviseDocumentSettings;
use App\Jobs\ApplySemanticFilter;
use App\Jobs\FetchDocument;
use App\Jobs\ScreenDocument;
use App\Models\Document;
use App\Models\EditorialPolicy;
use App\Models\SemanticFilterExample;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * A stand-in for the Embeddings API: a text's vector counts three words,
 * society (like this media), theory (unlike) and robot (either), so the
 * likeness of a text is plain to read from it.
 */
function fakeEmbeddings(): void
{
    Http::fake(['api.openai.com/v1/embeddings' => function (Request $request) {
        $data = array_map(fn (string $text, int $i): array => ['index' => $i, 'embedding' => [
            substr_count(strtolower($text), 'society') + 0.01,
            substr_count(strtolower($text), 'theory') + 0.01,
            substr_count(strtolower($text), 'robot') + 0.01,
        ]], $request['input'], array_keys($request['input']));

        return Http::response(['data' => $data, 'usage' => ['total_tokens' => 10 * count($data)]]);
    }]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.openai.key' => 'test-key']);
    EditorialPolicy::query()->create(['layer' => 'semantic_filter', 'body' => '', 'model' => 'text-embedding-3-large', 'threshold' => 0.10]);
    EditorialPolicy::query()->create(['layer' => 'semantic_like', 'body' => "society\n\n"]);
    EditorialPolicy::query()->create(['layer' => 'semantic_unlike', 'body' => 'theory']);
    $this->actingAs(User::factory()->create());
});

it('reads the definitions, the model and the threshold of the semantic filter', function () {
    expect(EditorialPolicy::semanticFilter())->toBe([
        'definitions' => [['side' => 'like', 'text' => 'society'], ['side' => 'unlike', 'text' => 'theory']],
        'model' => 'text-embedding-3-large',
        'threshold' => 0.1,
    ]);
});

// Likeness is the nearest "like" minus the nearest "unlike"; below the threshold the document goes no further.
it('sends a document like this media on to the screening and leaves one unlike it out', function () {
    fakeEmbeddings();
    $like = Document::factory()->fetched()->create(['title' => 'Robots in society', 'markdown' => "# Robots in society\n\nHow society takes to robots."]);
    $unlike = Document::factory()->fetched()->create(['title' => 'A theory of robots', 'markdown' => "# A theory of robots\n\nA theory, and another theory."]);

    (new ApplySemanticFilter($like))->handle(app(MeasureLikeness::class));
    (new ApplySemanticFilter($unlike))->handle(app(MeasureLikeness::class));

    expect($like->refresh()->likeness)->toBeGreaterThan(0.1)
        ->and($like->isBelowLikeness())->toBeFalse()
        ->and($like->likeness_detail['like']['label'])->toBe('society')
        ->and($unlike->refresh()->likeness)->toBeLessThan(0.1)
        ->and($unlike->isBelowLikeness())->toBeTrue()
        ->and($like->embedding->model)->toBe('text-embedding-3-large');
    Queue::assertPushed(ScreenDocument::class, 1);
    expect($like->refresh()->screening)->not->toBeNull()->and($unlike->refresh()->screening)->toBeNull();
    // The definitions are embedded once and kept: the second document called the model for itself only.
    Http::assertSentCount(3);
});

// A filter that cannot measure does not hold a document back.
it('sends the document on to the screening when the embedding fails', function () {
    Http::fake(['api.openai.com/v1/embeddings' => Http::response(['error' => ['message' => 'down']], 500)]);
    $document = Document::factory()->fetched()->create();

    (new ApplySemanticFilter($document))->handle(app(MeasureLikeness::class));

    expect($document->refresh()->likeness)->toBeNull()->and($document->likeness_detail)->toHaveKey('error');
    Queue::assertPushed(ScreenDocument::class, 1);
});

// A document a person marked is an example: every other document is measured against it too, and it is never its own example.
it('measures documents against the examples a person marked', function () {
    fakeEmbeddings();
    $example = Document::factory()->fetched()->create(['title' => 'Robot robot robot', 'markdown' => 'robot robot robot']);
    $near = Document::factory()->fetched()->create(['title' => 'Robot', 'markdown' => 'robot robot']);
    $measure = app(MeasureLikeness::class);
    $measure($example);
    $measure($near);
    expect($near->refresh()->isBelowLikeness())->toBeTrue();

    Livewire::test('pages::editorial.documents.show', ['document' => $example])->call('markExample', 'like');

    expect(SemanticFilterExample::query()->sole())->toMatchArray(['document_id' => $example->id, 'side' => 'like'])
        ->and($near->refresh()->likeness_detail['like']['label'])->toBe("example:{$example->id}:Robot robot robot")
        ->and($near->isBelowLikeness())->toBeFalse()
        // The example is measured without itself: only the definitions are left to it.
        ->and($example->refresh()->likeness_detail['like']['label'])->toBe('society');
    // An example is not a verdict.
    expect($example->human_decision)->toBeNull();

    Livewire::test('pages::editorial.documents.show', ['document' => $example])->call('markExample', 'none');
    expect(SemanticFilterExample::query()->count())->toBe(0)->and($near->refresh()->isBelowLikeness())->toBeTrue();
});

// Saved on the screen of a side, the definitions measure the embedded documents again without embedding them again.
it('saves the definitions of each side on a screen of its own and measures again', function () {
    fakeEmbeddings();
    $document = Document::factory()->fetched()->create(['title' => 'Robots', 'markdown' => 'robot']);
    app(MeasureLikeness::class)($document);
    $calls = count(Http::recorded());

    Livewire::test('pages::editorial.semantic-filter.show', ['side' => 'like'])
        ->assertSee('このメディアらしいもの')
        ->set('definitions', 'robot')
        ->call('save');
    Livewire::test('pages::editorial.documents.index')
        ->assertSee('意味フィルタ')
        ->set('semanticFilterThreshold', '0.05')
        ->call('saveSemanticFilter')
        ->assertHasNoErrors();
    $this->get(route('editorial.semantic-filter.show', 'unlike'))->assertOk()->assertSee('theory');
    $this->get('/editorial/semantic-filter/other')->assertNotFound();

    expect(EditorialPolicy::semanticFilter()['threshold'])->toBe(0.05)
        ->and($document->refresh()->likeness_detail['like']['label'])->toBe('robot')
        ->and($document->isBelowLikeness())->toBeFalse();
    // One call for the new definition line only.
    expect(count(Http::recorded()) - $calls)->toBe(1);
});

// The bulk screening and a queued screening pass over what the filter left out; so does a queued full-text fetch.
it('keeps what the semantic filter left out away from the screening and the full text', function () {
    $out = Document::factory()->fetched()->create(['title' => 'Left out', 'likeness' => -0.2]);
    $in = Document::factory()->fetched()->create(['title' => 'Let through', 'likeness' => 0.2]);

    Livewire::test('pages::editorial.documents.index')->call('screenDocuments');
    Queue::assertPushed(ScreenDocument::class, 1);
    expect($in->refresh()->screening)->not->toBeNull()->and($out->refresh()->screening)->toBeNull();

    $screening = ScreenDocument::queueFor($out);
    (new ScreenDocument($screening))->handle(app(ProposeDecision::class), app(ReviseDocumentSettings::class));
    expect($screening->refresh())->toMatchArray(['status' => 'failed'])->and($screening->status_message)->toContain('意味フィルタ');

    $summary = Document::factory()->fetched()->create(['format' => 'feed', 'likeness' => -0.2, 'status' => 'fetching', 'url' => 'https://arxiv.org/abs/1']);
    (new FetchDocument($summary))->handle(app(ReadDocument::class), app(ProposeDocumentSettings::class), app(FetchFavicon::class));
    expect($summary->refresh()->status)->toBe('fetched')->and($summary->format)->toBe('feed');
    Http::assertNothingSent();
});
