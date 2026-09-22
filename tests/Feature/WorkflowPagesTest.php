<?php

use App\Jobs\ConfigureSource;
use App\Models\Article;
use App\Models\Document;
use App\Models\Material;
use App\Models\Source;
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
    $update = $material->document;
    $source = $update->source;

    foreach ([
        route('sources.index'), route('sources.show', $source),
        route('documents.index'), route('documents.show', $update),
        route('materials.index'), route('materials.show', $material),
        route('articles.index'), route('articles.show', $article),
        route('editorial-policy'),
        route('dashboard'),
    ] as $url) {
        $this->get($url)->assertOk();
    }

    // The source's detail lists only the documents whose fetch failed, with the reason and the title cut at 31 characters (the whole title is the tooltip); the fetched ones are on 文書.
    Document::factory()->for($source)->create(['title' => str_repeat('あ', 40), 'status' => 'failed', 'status_message' => 'HTTP request returned status code 404']);
    $this->get(route('sources.show', $source))->assertSee($source->name)->assertDontSee($update->title)
        ->assertSee(str_repeat('あ', 31).'…')->assertSee('HTTP request returned status code 404');
    // The list counts those failures per source.
    $sources = Livewire::test('pages::sources.index')->assertSee('取得失敗数')->instance()->sources;
    expect($sources->firstWhere('id', $source->id)->failed_documents_count)->toBe(1);
    // The source's address is a tooltip on the link icon, not a column.
    $this->get(route('sources.index'))->assertSee($source->name)->assertSee(e($source->url), false);
    $this->get(route('articles.show', $article))->assertSee($article->title)->assertSee($update->title);
});

// Timestamps are stored in UTC and shown in the display timezone (JST by default).
it('shows timestamps in the display timezone', function () {
    $update = Document::factory()->fetched()->create(['fetched_at' => '2026-09-21 14:37:00']);

    expect($update->refresh()->fetched_at?->toIso8601String())->toBe('2026-09-21T14:37:00+00:00');
    $this->get(route('documents.show', $update))->assertSee('2026-09-21 23:37');
});

it('redirects guests to the login page', function () {
    auth()->logout();

    $this->get(route('sources.index'))->assertRedirect(route('login'));
});

// Only a source is added by hand; everything under it follows from background jobs
// (FetchUpdatesTest, FetchDocumentTest, ExtractMaterialTest, GenerateArticleTest).
it('lets the user add a source by hand, and follows one record through the stages', function () {
    Livewire::test('pages::sources.index')
        ->set('name', 'NEDO')->set('url', 'https://www.nedo.go.jp/')
        ->call('add')->assertHasNoErrors();
    $source = Source::query()->sole();
    expect($source->status)->toBe('pending');
    Queue::assertPushed(ConfigureSource::class, fn (ConfigureSource $job): bool => $job->source->is($source));

    $update = Document::factory()->for($source)->fetched()->create(['title' => 'Press release', 'url' => 'https://www.nedo.go.jp/news/press/1.html', 'published_at' => '2026-09-17', 'markdown' => '# Press release']);

    $material = Material::factory()->for($update)->create(['data' => ['summary' => 'ammonia burner', 'topics' => ['energy']]]);

    Article::factory()->for($material)->create(['title' => 'Article', 'body' => 'Body text']);

    expect($update->source->is($source))->toBeTrue()
        ->and($update->fetched_at)->not->toBeNull()
        ->and($material->data)->toEqual(['summary' => 'ammonia burner', 'topics' => ['energy']])
        ->and(Article::query()->sole()->material->is($material))->toBeTrue();
});

// The list screens are paged: 10 rows unless the user picks 25 / 50 / 100, kept in the URL.
it('pages the sources and documents lists by the chosen rows per page', function () {
    $sources = Source::factory()->count(12)->sequence(fn ($sequence) => ['name' => 'Source '.($sequence->index + 1)])->create();
    Document::factory()->fetched()->count(12)->sequence(fn ($sequence) => ['source_id' => $sources[0]->id, 'title' => 'Document '.($sequence->index + 1)])->create();

    foreach ([['pages::sources.index', 'Source'], ['pages::documents.index', 'Document']] as [$page, $prefix]) {
        Livewire::test($page)
            ->assertSee("{$prefix} 12")->assertDontSee("{$prefix} 1<")->assertSee('表示: 1 – 10 ／ 12 件')
            ->set('rowsPerPage', 25)->assertSee("{$prefix} 1<", false)
            ->set('rowsPerPage', 10)->call('gotoPage', 2)->assertSee("{$prefix} 2<", false)->assertDontSee("{$prefix} 12");
    }

    $this->get(route('sources.index', ['rowsPerPage' => 25]))->assertSee('Source 1<', false);
});

// 文書 lists fetched documents only, sorted and filtered by source, published date, format and fetched time.
it('lists fetched documents sorted and filtered by the chosen column', function () {
    [$a, $b] = Source::factory()->count(2)->sequence(['name' => 'A 研究所'], ['name' => 'B 研究所'])->create();
    Document::factory()->fetched()->for($a)->create(['title' => 'Old HTML', 'published_at' => '2026-01-10', 'fetched_at' => '2026-09-01 00:00:00']);
    Document::factory()->fetched()->for($b)->create(['title' => 'New PDF', 'format' => 'pdf', 'published_at' => '2026-03-10', 'fetched_at' => '2026-09-20 00:00:00']);
    Document::factory()->for($a)->create(['title' => 'Not fetched yet']);
    Document::factory()->for($a)->create(['title' => 'Failed one', 'status' => 'failed']);

    $titles = fn ($component) => $component->instance()->documents->pluck('title')->all();

    $component = Livewire::test('pages::documents.index')->assertDontSee('Not fetched yet')->assertDontSee('Failed one');
    expect($titles($component))->toBe(['New PDF', 'Old HTML']);
    expect($titles($component->call('sortBy', 'fetched_at')))->toBe(['Old HTML', 'New PDF']);
    expect($titles($component->call('sortBy', 'source')))->toBe(['New PDF', 'Old HTML']);
    expect($titles($component->call('sortBy', 'source')))->toBe(['Old HTML', 'New PDF']);
    expect($titles($component->call('sortBy', 'published_at')))->toBe(['New PDF', 'Old HTML']);
    expect($titles($component->call('sortBy', 'format')))->toBe(['New PDF', 'Old HTML']);

    expect($titles($component->set('source', (string) $a->id)))->toBe(['Old HTML']);
    expect($titles($component->set('source', '')->set('format', 'pdf')))->toBe(['New PDF']);
    expect($titles($component->set('format', '')->set('publishedFrom', '2026-02-01')))->toBe(['New PDF']);
    expect($titles($component->set('publishedFrom', '')->set('publishedTo', '2026-02-01')))->toBe(['Old HTML']);
    // The fetched time is filtered by days of the display timezone: 2026-09-01 00:00 UTC is 09:00 on 2026-09-01 in Tokyo.
    expect($titles($component->set('publishedTo', '')->set('fetchedFrom', '2026-09-01')->set('fetchedTo', '2026-09-01')))->toBe(['Old HTML']);
    expect($titles($component->set('fetchedFrom', '2026-09-02')->set('fetchedTo', '')))->toBe(['New PDF']);
});

it('lets the user edit and delete a source from its detail screen', function () {
    $source = Source::factory()->create(['name' => 'DARPA - Bews']);
    $update = Document::factory()->for($source)->create();

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
        ->and(Document::query()->whereKey($update->id)->exists())->toBeFalse();
});
