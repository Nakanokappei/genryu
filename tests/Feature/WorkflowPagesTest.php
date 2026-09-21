<?php

use App\Jobs\ConfigureSource;
use App\Models\Article;
use App\Models\Document;
use App\Models\Material;
use App\Models\Source;
use App\Models\UpdateEntry;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    // Adding a source queues its configuration; the queue is faked so no job runs (or reaches the network) here.
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

// Every stage has a list and a detail screen that render for a signed-in user.
it('renders the list and detail screen of every stage', function () {
    $article = Article::factory()->create();
    $material = $article->material;
    $document = $material->document;
    $update = $document->updateEntry;
    $source = $update->source;

    foreach ([
        route('sources.index'), route('sources.show', $source),
        route('updates.index'), route('updates.show', $update),
        route('documents.index'), route('documents.show', $document),
        route('materials.index'), route('materials.show', $material),
        route('articles.index'), route('articles.show', $article),
        route('editorial-policy'),
        route('dashboard'),
    ] as $url) {
        $this->get($url)->assertOk();
    }

    $this->get(route('sources.show', $source))->assertSee($source->name)->assertSee($update->title);
    $this->get(route('articles.show', $article))->assertSee($article->title)->assertSee($document->title);
});

// Timestamps are stored in UTC and shown in the display timezone (JST by default).
it('shows timestamps in the display timezone', function () {
    $document = Document::factory()->create(['fetched_at' => '2026-09-21 14:37:00']);

    expect($document->refresh()->fetched_at?->toIso8601String())->toBe('2026-09-21T14:37:00+00:00');
    $this->get(route('documents.show', $document))->assertSee('2026-09-21 23:37');
});

it('redirects guests to the login page', function () {
    auth()->logout();

    $this->get(route('sources.index'))->assertRedirect(route('login'));
});

// The hand-entry forms create one record each, following the flow from source to article.
// Documents, materials and articles are never added by hand: they come from background jobs
// (FetchDocumentTest, ExtractMaterialTest, GenerateArticleTest).
it('lets the user add a record on each stage by hand', function () {
    Livewire::test('pages::sources.index')
        ->set('name', 'NEDO')->set('url', 'https://www.nedo.go.jp/')
        ->call('add')->assertHasNoErrors();
    $source = Source::query()->sole();
    expect($source->status)->toBe('pending');
    Queue::assertPushed(ConfigureSource::class, fn (ConfigureSource $job): bool => $job->source->is($source));

    Livewire::test('pages::updates.index')
        ->set('source_id', (string) $source->id)->set('title', 'Press release')->set('url', 'https://www.nedo.go.jp/news/press/1.html')->set('published_at', '2026-09-17')
        ->call('add')->assertHasNoErrors();
    $update = UpdateEntry::query()->sole();

    $document = Document::factory()->for($update)->create(['title' => 'Press release', 'url' => $update->url, 'markdown' => '# Press release']);

    $material = Material::factory()->for($document)->create(['data' => ['summary' => 'ammonia burner', 'topics' => ['energy']]]);

    Article::factory()->for($material)->create(['title' => 'Article', 'body' => 'Body text']);

    expect($update->source->is($source))->toBeTrue()
        ->and($document->fetched_at)->not->toBeNull()
        ->and($material->data)->toEqual(['summary' => 'ammonia burner', 'topics' => ['energy']])
        ->and(Article::query()->sole()->material->is($material))->toBeTrue();
});

it('lets the user edit and delete a source from its detail screen', function () {
    $source = Source::factory()->create(['name' => 'DARPA - Bews']);
    $update = UpdateEntry::factory()->for($source)->create();

    Livewire::test('pages::sources.show', ['source' => $source])
        ->assertSet('name', 'DARPA - Bews')
        ->set('name', 'DARPA - News')
        ->call('save')->assertHasNoErrors();
    expect($source->refresh()->name)->toBe('DARPA - News');

    Livewire::test('pages::sources.show', ['source' => $source])
        ->set('url', 'not a url')
        ->call('save')->assertHasErrors(['url']);

    Livewire::test('pages::sources.show', ['source' => $source])
        ->call('delete')
        ->assertRedirect(route('sources.index'));
    expect(Source::query()->count())->toBe(0)
        ->and(UpdateEntry::query()->whereKey($update->id)->exists())->toBeFalse();
});
