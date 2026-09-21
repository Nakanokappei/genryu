<?php

use App\Actions\FetchUpdates;
use App\Actions\ProposeDocumentSettings;
use App\Actions\ReadDocument;
use App\Jobs\FetchDocument;
use App\Models\Document;
use App\Models\Source;
use App\Models\UpdateEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const DOCUMENT_BODY = '<p>The agency announced a programme to develop compact ammonia burners for industrial furnaces, cutting CO2 from heat.</p>'
    .'<h2>Background</h2><p>Ammonia carries hydrogen without the cost of liquefaction and can be burned directly.</p>'
    .'<ul><li>Budget: 2 billion yen</li><li>Period: 2026-2029</li></ul>'
    .'<p>See the <a href="/docs/plan.pdf">plan</a> and <img src="../img/burner.png" alt="burner"></p>';

const DOCUMENT_PAGE = '<html><head><meta charset="utf-8"><title>Site</title><script>var x = 1;</script></head><body>'
    .'<nav><a href="/">Home</a><a href="/news">News</a></nav>'
    .'<article><h1>Ammonia burner programme</h1><time datetime="2026-09-17">2026-09-17</time>'.DOCUMENT_BODY
    .'<div class="share"><a href="https://x.com/share">Share on X</a></div></article>'
    .'<footer>Copyright</footer></body></html>';

/**
 * What the agent would answer, as the OpenAI chat completion wire format.
 */
function documentAgentAnswer(array $selectors): array
{
    return ['choices' => [['message' => ['content' => json_encode($selectors)]]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*/robots.txt' => Http::response('', 404)]);
    Storage::fake('local');
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4o-mini']);
    $this->actingAs(User::factory()->create());
});

function fetchDocument(UpdateEntry $entry): Document
{
    $document = Document::query()->updateOrCreate(['update_entry_id' => $entry->id], ['title' => $entry->title, 'url' => $entry->url, 'status' => 'fetching']);
    (new FetchDocument($document))->handle(app(ReadDocument::class), app(ProposeDocumentSettings::class));

    return $document->refresh();
}

it('reads an HTML page into Markdown with the document settings of the source and keeps the original', function () {
    Http::fake(['www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);
    $source = Source::factory()->create(['document_config' => ['content' => 'article', 'remove' => '.share']]);
    $entry = UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1', 'title' => 'Ammonia burner programme']);

    $document = fetchDocument($entry);

    expect($document)->toMatchArray(['status' => 'fetched', 'format' => 'html', 'original_path' => "documents/{$source->id}/{$entry->id}.html", 'status_message' => null])
        ->and($document->fetched_at)->not->toBeNull()
        ->and($document->markdown)->toStartWith('# Ammonia burner programme')
        ->toContain('## Background')
        ->toContain('- Budget: 2 billion yen')
        // Links and images resolve against the page; navigation, scripts and the share bar are gone.
        ->toContain('[plan](https://www.example.org/docs/plan.pdf)')
        ->toContain('![burner](https://www.example.org/img/burner.png)')
        ->not->toContain('Home')->not->toContain('Share on X')->not->toContain('var x')->not->toContain('Copyright');
    Storage::disk('local')->assertExists("documents/{$source->id}/{$entry->id}.html");
    expect(Storage::disk('local')->get("documents/{$source->id}/{$entry->id}.html"))->toBe(DOCUMENT_PAGE);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

it('asks the agent for document settings when the source has none, verifies them on the page, and saves them for the next documents', function () {
    Http::fake([
        'www.example.org/news/*' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'article', 'remove' => '.share'])),
    ]);
    $source = Source::factory()->create();
    $first = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($first->status)->toBe('fetched')
        ->and($first->status_message)->toContain('エージェントが提案した文書の設定')
        ->and($first->markdown)->not->toContain('Share on X')
        ->and($source->refresh()->document_config)->toEqual(['content' => 'article', 'remove' => '.share']);
    // The agent receives the page, without scripts, and must answer JSON.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com')
        && $request['response_format']['type'] === 'json_object'
        && str_contains($request['messages'][1]['content'], '<article>')
        && ! str_contains($request['messages'][1]['content'], 'var x'));

    // The second document of the source is read with the saved settings: no second agent call.
    $second = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/2']));
    expect($second->status)->toBe('fetched')->and($second->status_message)->toBeNull();
    Http::assertSentCount(4); // robots.txt, page 1, agent, page 2
});

it('falls back to generic selectors when the proposed content selector finds nothing, and saves what worked', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'div.press-body', 'remove' => ''])),
    ]);
    $source = Source::factory()->create();

    $document = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('fetched')
        ->and($source->refresh()->document_config)->toEqual(['content' => 'article', 'remove' => '']);
});

// The site changed its layout: the saved settings match nothing, so the agent is asked again.
it('asks the agent again when the saved document settings no longer match the page', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response(DOCUMENT_PAGE, 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'article', 'remove' => ''])),
    ]);
    $source = Source::factory()->create(['document_config' => ['content' => 'div.old-layout', 'remove' => '']]);

    $document = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('fetched')
        ->and($source->refresh()->document_config['content'])->toBe('article');
});

it('fails with a clear message when neither the proposal nor the generic selectors find a body', function () {
    Http::fake([
        'www.example.org/news/1' => Http::response('<html><body><div class="x"><p>Short.</p></div></body></html>', 200, ['Content-Type' => 'text/html']),
        'api.openai.com/*' => Http::response(documentAgentAnswer(['content' => 'div.x', 'remove' => ''])),
    ]);
    $source = Source::factory()->create();

    $document = fetchDocument(UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('failed')
        ->and($document->status_message)->toContain('本文を見つけられませんでした')
        ->and($source->refresh()->document_config)->toBeNull();
});

it('reads a PDF as text and keeps the original', function () {
    $pdf = (string) file_get_contents(base_path('tests/Fixtures/press-release.pdf'));
    Http::fake(['www.example.org/press/1.pdf' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])]);
    $source = Source::factory()->create();
    $entry = UpdateEntry::factory()->for($source)->create(['url' => 'https://www.example.org/press/1.pdf']);

    $document = fetchDocument($entry);

    expect($document)->toMatchArray(['status' => 'fetched', 'format' => 'pdf', 'original_path' => "documents/{$source->id}/{$entry->id}.pdf"])
        ->and($document->markdown)->toBe("Hello from a PDF press release.\nSecond line of the release.");
    Storage::disk('local')->assertExists("documents/{$source->id}/{$entry->id}.pdf");
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

it('records a failure instead of throwing when the page cannot be fetched', function () {
    Http::fake(['www.example.org/*' => Http::response('gone', 500)]);

    $document = fetchDocument(UpdateEntry::factory()->create(['url' => 'https://www.example.org/news/1']));

    expect($document->status)->toBe('failed')->and($document->status_message)->toContain('500');
});

it('queues a fetch for every new update entry, and only for new ones', function () {
    Queue::fake();
    $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
        .'<item><title>One</title><link>https://www.example.org/news/1</link></item>'
        .'<item><title>Two</title><link>https://www.example.org/news/2</link></item></channel></rss>';
    Http::fake(['www.example.org/rss.xml' => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml'])]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/rss.xml']);

    app(FetchUpdates::class)($source);
    app(FetchUpdates::class)($source);

    Queue::assertPushed(FetchDocument::class, 2);
    expect(Document::query()->where('status', 'fetching')->count())->toBe(2)
        ->and(Document::query()->where('url', 'https://www.example.org/news/1')->sole()->title)->toBe('One');
});

it('queues the missing and failed documents of a source, and one document again, from the screens', function () {
    Queue::fake();
    $source = Source::factory()->create();
    $missing = UpdateEntry::factory()->for($source)->create();
    $failed = UpdateEntry::factory()->for($source)->create();
    Document::factory()->for($failed)->create(['status' => 'failed']);
    $fetched = UpdateEntry::factory()->for($source)->create();
    Document::factory()->for($fetched)->create();

    Livewire::test('pages::sources.show', ['source' => $source])->call('fetchDocuments');

    Queue::assertPushed(FetchDocument::class, 2);
    expect($missing->document?->status)->toBe('fetching')
        ->and($failed->document()->sole()->status)->toBe('fetching')
        ->and($fetched->document()->sole()->status)->toBe('fetched');

    Livewire::test('pages::updates.show', ['updateEntry' => $fetched])->call('fetchDocument');
    Livewire::test('pages::documents.show', ['document' => $fetched->document()->sole()])->call('fetch');

    Queue::assertPushed(FetchDocument::class, 4);
    expect($fetched->document()->sole()->status)->toBe('fetching')
        ->and(Document::query()->count())->toBe(3);
});

it('saves the document settings from the source detail screen', function () {
    $source = Source::factory()->create();

    Livewire::test('pages::sources.show', ['source' => $source])
        ->set('documentSettings.content', 'article')->set('documentSettings.remove', '.share')
        ->call('saveDocumentSettings')->assertHasNoErrors();
    expect($source->refresh()->document_config)->toEqual(['content' => 'article', 'remove' => '.share']);

    // Clearing the content selector hands the settings back to the agent.
    Livewire::test('pages::sources.show', ['source' => $source])
        ->assertSet('documentSettings.content', 'article')
        ->set('documentSettings.content', '')
        ->call('saveDocumentSettings')->assertHasNoErrors();
    expect($source->refresh()->document_config)->toBeNull();
});

it('serves the original file', function () {
    Storage::disk('local')->put('documents/1/1.html', DOCUMENT_PAGE);
    $document = Document::factory()->create(['original_path' => 'documents/1/1.html']);

    $this->get(route('documents.original', $document))->assertOk()->assertDownload('1.html');
});
