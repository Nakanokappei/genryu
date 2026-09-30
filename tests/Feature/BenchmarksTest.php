<?php

use App\Actions\CompareWithBenchmarks;
use App\Actions\FetchFavicon;
use App\Actions\MeasureLikeness;
use App\Actions\ProposeDocumentSettings;
use App\Actions\ReadDocument;
use App\Jobs\ApplySemanticFilter;
use App\Jobs\CompareDocumentsWithBenchmarks;
use App\Jobs\ConfigureSource;
use App\Jobs\FetchDocument;
use App\Jobs\ScreenDocument;
use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Models\EditorialPolicy;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * A benchmark's article page at review.example.com and a stand-in for the
 * Embeddings API whose vector counts three words: society, theory and robot.
 */
function fakeBenchmarkSite(): void
{
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        '*/favicon.ico' => Http::response('', 404),
        'review.example.com/*' => Http::response('<html><body><article><h1>A theory of robots</h1><p>Robot theory, theory and more theory. '.str_repeat('The robot works to a theory. ', 10).'</p></article></body></html>', 200, ['Content-Type' => 'text/html']),
        'api.openai.com/v1/embeddings' => function (Request $request) {
            $data = array_map(fn (string $text, int $i): array => ['index' => $i, 'embedding' => [
                substr_count(strtolower($text), 'society') + 0.01,
                substr_count(strtolower($text), 'theory') + 0.01,
                substr_count(strtolower($text), 'robot') + 0.01,
            ]], $request['input'], array_keys($request['input']));

            return Http::response(['data' => $data, 'usage' => ['total_tokens' => 10 * count($data)]]);
        },
    ]);
}

/** A benchmark with one listed document, not fetched yet. */
function benchmarkDocument(): Document
{
    $benchmark = Source::factory()->create(['name' => 'Tech review', 'url' => 'https://review.example.com/', 'is_benchmark' => true, 'document_settings' => ['content' => 'article', 'date' => '', 'remove' => '', 'fixed_text' => '']]);

    return Document::factory()->create(['source_id' => $benchmark->id, 'title' => 'A theory of robots', 'url' => 'https://review.example.com/theory', 'published_at' => now()->subDay()]);
}

/** Fetches a document as the queue would. */
function fetchBenchmarkDocument(Document $document): void
{
    (new FetchDocument($document))->handle(app(ReadDocument::class), app(ProposeDocumentSettings::class), app(FetchFavicon::class));
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    config(['services.openai.key' => 'test-key']);
    EditorialPolicy::query()->create(['layer' => 'semantic_filter', 'body' => '', 'model' => 'text-embedding-3-large', 'threshold' => 0.10]);
    EditorialPolicy::query()->create(['layer' => 'semantic_like', 'body' => 'society']);
    EditorialPolicy::query()->create(['layer' => 'semantic_unlike', 'body' => 'theory']);
    $this->actingAs(User::factory()->create());
});

// A benchmark's document is fetched and read like any other, but only its embedding is kept, and it goes no further.
it('keeps only the embedding of a benchmark document', function () {
    fakeBenchmarkSite();
    $document = benchmarkDocument();

    fetchBenchmarkDocument($document);

    $document->refresh();
    expect($document->status)->toBe('fetched')
        ->and($document->markdown)->toBeNull()
        ->and($document->original_path)->toBeNull()
        ->and($document->revisions()->count())->toBe(0)
        ->and($document->hasShortBody())->toBeFalse()
        ->and($document->embedding->model)->toBe('text-embedding-3-large')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNotPushed(ApplySemanticFilter::class);
});

// Neither the semantic filter nor the screening takes a benchmark's document.
it('never filters or screens a benchmark document', function () {
    fakeBenchmarkSite();
    $document = benchmarkDocument();
    fetchBenchmarkDocument($document);

    (new ApplySemanticFilter($document->refresh()))->handle(app(MeasureLikeness::class));
    app(MeasureLikeness::class)->again();
    $screening = ScreenDocument::queueFor($document);
    app()->call([new ScreenDocument($screening), 'handle']);

    expect($document->refresh()->likeness)->toBeNull()
        ->and($screening->refresh()->status)->toBe('failed')
        ->and($screening->decision)->toBeNull();
});

// The semantic filter records the nearest document of each benchmark, and the likeness alone still decides.
it('records the benchmark similarity beside the likeness without deciding anything', function () {
    fakeBenchmarkSite();
    fetchBenchmarkDocument(benchmarkDocument());
    // A benchmark document older than the window is not compared with.
    $old = Document::factory()->fetched()->create(['source_id' => Source::factory()->create(['name' => 'Old review', 'is_benchmark' => true])->id, 'published_at' => now()->subDays(Source::BENCHMARK_WINDOW_DAYS + 1), 'markdown' => null]);
    DocumentEmbedding::query()->create(['document_id' => $old->id, 'model' => 'text-embedding-3-large', 'vector' => [0.0, 1.0, 0.0]]);
    $document = Document::factory()->fetched()->create(['title' => 'A theory of robots', 'markdown' => 'A robot theory, theory, theory.']);

    (new ApplySemanticFilter($document))->handle(app(MeasureLikeness::class));

    $document->refresh();
    expect($document->isLeftOut())->toBeTrue()
        ->and($document->benchmark_similarity)->toBeGreaterThan(0.8)
        ->and($document->benchmark_detail['benchmarks'])->toHaveCount(1)
        ->and($document->benchmark_detail['benchmarks'][0])->toMatchArray(['benchmark' => 'Tech review', 'url' => 'https://review.example.com/theory'])
        ->and($document->latestScreening)->toBeNull();
});

// Comparing again takes the sources' documents only and needs no model call.
it('compares every embedded document of the sources again without calling the model', function () {
    fakeBenchmarkSite();
    $benchmarkDocument = benchmarkDocument();
    fetchBenchmarkDocument($benchmarkDocument);
    $document = Document::factory()->fetched()->create(['title' => 'Robots in society', 'markdown' => 'Robots in society.']);
    app(MeasureLikeness::class)($document);
    Document::factory()->fetched()->create();
    $calls = count(Http::recorded());

    (new CompareDocumentsWithBenchmarks)->handle(app(CompareWithBenchmarks::class));

    expect($document->refresh()->benchmark_detail['benchmarks'][0]['url'])->toBe('https://review.example.com/theory')
        ->and($benchmarkDocument->refresh()->benchmark_similarity)->toBeNull()
        ->and(Document::query()->whereNotNull('benchmark_similarity')->count())->toBe(1)
        ->and(count(Http::recorded()))->toBe($calls);
});

// Added on its own screen, a benchmark is configured like a source and kept off the Sources and Documents screens.
it('adds a benchmark on its screen and keeps it apart from the sources', function () {
    Livewire::test('pages::editorial.benchmarks.index')
        ->set('name', 'Tech review')->set('url', 'https://review.example.com/')->call('add')
        ->assertHasNoErrors();

    $benchmark = Source::query()->sole();
    expect($benchmark->is_benchmark)->toBeTrue();
    Queue::assertPushed(ConfigureSource::class, 1);
    Document::factory()->create(['source_id' => $benchmark->id, 'title' => 'Benchmark article']);
    Source::factory()->create(['name' => 'Primary agency']);

    $this->get(route('editorial.benchmarks.index'))->assertOk()->assertSee('Tech review')->assertSee('Benchmark article');
    $this->get(route('editorial.sources.index'))->assertOk()->assertSee('Primary agency')->assertDontSee('Tech review');
    $this->get(route('editorial.documents.index'))->assertOk()->assertDontSee('Benchmark article');
});
