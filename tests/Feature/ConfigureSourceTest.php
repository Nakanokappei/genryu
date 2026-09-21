<?php

use App\Actions\FetchUpdates;
use App\Actions\ProposeListSettings;
use App\Jobs\ConfigureSource;
use App\Models\Source;
use App\Models\UpdateEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const CONFIGURE_RSS = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title><item><title>One</title><link>https://www.example.org/news/1</link></item></channel></rss>';

const CONFIGURE_LIST = '<html><body><table class="table1"><tr><th>掲載日</th><th>件名</th></tr>'
    .'<tr><td><time datetime="2026-09-17">2026年9月17日</time></td><td><a href="/news/a.html">A</a></td></tr>'
    .'<tr><td><time datetime="2026-09-08">2026年9月8日</time></td><td><a href="/news/b.html">B</a></td></tr>'
    .'<tr><td><time datetime="2026-09-01">2026年9月1日</time></td><td><a href="/news/c.html">C</a></td></tr>'
    .'</table></body></html>';

/**
 * What the agent would answer, as the OpenAI chat completion wire format.
 */
function agentAnswer(array $selectors): array
{
    return ['choices' => [['message' => ['content' => json_encode($selectors)]]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*/robots.txt' => Http::response('', 404)]);
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4o-mini']);
    // The first update-list read queues a document fetch per entry (stage 2.2); faked so nothing runs here.
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

function configure(Source $source): Source
{
    (new ConfigureSource($source))->handle(app(FetchUpdates::class), app(ProposeListSettings::class));

    return $source->refresh();
}

it('finds a feed deterministically, without asking the agent, and reads it', function () {
    Http::fake([
        'www.example.org/news' => Http::response('<html><head><link rel="alternate" type="application/rss+xml" href="/rss.xml"></head></html>', 200, ['Content-Type' => 'text/html']),
        'www.example.org/rss.xml' => Http::response(CONFIGURE_RSS, 200, ['Content-Type' => 'application/rss+xml']),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/news']));

    expect($source)->toMatchArray(['status' => 'ready', 'feed_url' => 'https://www.example.org/rss.xml', 'list_config' => null])
        ->and($source->status_message)->toContain('フィードを見つけました')
        ->and(UpdateEntry::query()->count())->toBe(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
});

it('asks the agent for HTML list settings when there is no feed, verifies them, saves them and reads the list', function () {
    Http::fake([
        'www.example.org/list' => Http::response(CONFIGURE_LIST, 200, ['Content-Type' => 'text/html']),
        'www.example.org/*' => Http::response('not found', 404),
        'api.openai.com/*' => Http::response(agentAnswer(['item' => 'table.table1 tr', 'title' => 'td a', 'date' => 'time', 'next' => ''])),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/list']));

    expect($source->status)->toBe('ready')
        ->and($source->list_config)->toEqual(['item' => 'table.table1 tr', 'title' => 'td a', 'date' => 'time', 'next' => '', 'max_pages' => 3])
        ->and($source->status_message)->toContain('3 件')
        ->and(UpdateEntry::query()->count())->toBe(3);
    // The agent receives the page, without scripts, and must answer JSON.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com')
        && $request['response_format']['type'] === 'json_object'
        && str_contains($request['messages'][1]['content'], 'table1'));
});

// CNRS: /rss.xml exists but is a newsletter, not the press list the operator pointed at.
it('reports how many feed entries the page links to, and skips feeds when told to read the page as HTML', function () {
    Http::fake([
        'www.example.org/list' => Http::response(CONFIGURE_LIST, 200, ['Content-Type' => 'text/html']),
        'www.example.org/rss.xml' => Http::response(CONFIGURE_RSS, 200, ['Content-Type' => 'application/rss+xml']),
        'www.example.org/*' => Http::response('not found', 404),
        'api.openai.com/*' => Http::response(agentAnswer(['item' => 'table.table1 tr', 'title' => 'td a', 'date' => 'time', 'next' => ''])),
    ]);

    $probed = configure(Source::factory()->create(['url' => 'https://www.example.org/list']));
    expect($probed->status)->toBe('ready')->and($probed->feed_url)->toBe('https://www.example.org/rss.xml')
        ->and($probed->status_message)->toContain('0 件');

    $asHtml = configure(Source::factory()->create(['url' => 'https://www.example.org/list', 'read_as_html' => true]));
    expect($asHtml->status)->toBe('ready')->and($asHtml->feed_url)->toBeNull()
        ->and($asHtml->list_config['item'])->toBe('table.table1 tr');
});

it('saves the read-as-HTML choice from the source detail screen', function () {
    $source = Source::factory()->create();

    Livewire::test('pages::sources.show', ['source' => $source])->set('readAsHtml', true);

    expect($source->refresh()->read_as_html)->toBeTrue();
});

// CNRS: <a href><h2 class="article__title">…</h2></a>; the agent proposed "h2.article__title a", which is inside out.
it('falls back to generic title and date selectors when the proposed ones find nothing, and saves what worked', function () {
    $row = fn (int $i): string => "<div class=\"views-row\"><a href=\"/img/{$i}\" class=\"article__link\"><img alt=\"\"></a><time class=\"datetime\" datetime=\"2026-09-0{$i}\">0{$i}.09.2026</time><a href=\"/fr/presse/item-{$i}\"><h2 class=\"article__title\">Item {$i}</h2></a></div>";
    $page = '<html><body>'.$row(1).$row(2).$row(3).$row(4).'</body></html>';
    Http::fake([
        'www.example.org/list' => Http::response($page, 200, ['Content-Type' => 'text/html']),
        'www.example.org/*' => Http::response('not found', 404),
        'api.openai.com/*' => Http::response(agentAnswer(['item' => 'div.views-row', 'title' => 'h2.article__title a', 'date' => 'time.datetime', 'next' => ''])),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/list', 'read_as_html' => true]));

    expect($source->status)->toBe('ready')
        ->and($source->list_config)->toMatchArray(['item' => 'div.views-row', 'title' => 'h1, h2, h3, h4', 'date' => 'time.datetime'])
        ->and(UpdateEntry::query()->pluck('url')->all())->toBe(['https://www.example.org/fr/presse/item-1', 'https://www.example.org/fr/presse/item-2', 'https://www.example.org/fr/presse/item-3', 'https://www.example.org/fr/presse/item-4'])
        ->and(UpdateEntry::query()->where('title', 'Item 2')->sole()->published_at?->toDateString())->toBe('2026-09-02');
});

it('does not save a proposal that matches too little on the page', function () {
    Http::fake([
        'www.example.org/list' => Http::response(CONFIGURE_LIST, 200, ['Content-Type' => 'text/html']),
        'www.example.org/*' => Http::response('not found', 404),
        'api.openai.com/*' => Http::response(agentAnswer(['item' => 'div.card', 'title' => 'a', 'date' => '', 'next' => ''])),
    ]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/list']));

    expect($source->status)->toBe('failed')
        ->and($source->status_message)->toContain('0 件')
        ->and($source->list_config)->toBeNull()
        ->and(UpdateEntry::query()->count())->toBe(0);
});

it('records a failure instead of throwing when the page cannot be fetched', function () {
    Http::fake(['www.example.org/*' => Http::response('gone', 500)]);
    $source = configure(Source::factory()->create(['url' => 'https://www.example.org/list']));

    expect($source->status)->toBe('failed')->and($source->status_message)->toContain('500');
});

it('can be queued again from the source detail screen', function () {
    Queue::fake();
    $source = Source::factory()->create(['status' => 'failed', 'status_message' => 'boom']);

    Livewire::test('pages::sources.show', ['source' => $source])->call('configure');

    expect($source->refresh()->status)->toBe('pending');
    Queue::assertPushed(ConfigureSource::class);
});
