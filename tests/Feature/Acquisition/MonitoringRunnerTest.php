<?php

use App\Acquisition\Application\DiscoveryRun;
use App\Acquisition\Application\Monitoring\MonitoringRun;
use App\Acquisition\Application\Monitoring\MonitoringRunner;
use App\Acquisition\Application\Monitoring\MonitoringSkipped;
use App\Acquisition\Application\Profile\ProfileApproval;
use App\Acquisition\Domain\Enums\HealthStatus;
use App\Acquisition\Domain\Enums\RunStatus;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Document;
use App\Acquisition\Domain\Models\DocumentRevision;
use App\Acquisition\Domain\Models\FetchObservation;
use App\Acquisition\Domain\Models\HealthObservation;
use App\Acquisition\Domain\Models\NormalizedArtifact;
use App\Acquisition\Domain\Models\RawArtifact;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * Mutable state of the monitored site. Http::fake() keeps the first
 * registered callback, so the callback reads the state at request time and
 * tests change the state instead of re-registering.
 */
final class MonitoredSiteState
{
    /** @var array<string, string> */
    public static array $current = [];
}

/**
 * The monitored site. $state changes what the site serves between runs:
 * feed=rss|empty|html, article=v1|v2|challenge, older=article|challenge.
 *
 * @param  array<string, string>  $state
 */
function fakeMonitoredSite(array $state = []): void
{
    MonitoredSiteState::$current = ['feed' => 'rss', 'article' => 'v1', 'older' => 'article', ...$state];

    if (test()->siteRegistered ?? false) {
        return;
    }

    test()->siteRegistered = true;

    Http::fake(function (Request $request) {
        $state = MonitoredSiteState::$current;
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);
        $etagFor = fn (string $body): string => '"'.substr(hash('sha256', $body), 0, 12).'"';
        $serve = function (string $body, string $type) use ($request, $etagFor) {
            $etag = $etagFor($body);

            if (($request->header('If-None-Match')[0] ?? null) === $etag) {
                return Http::response('', 304, ['ETag' => $etag]);
            }

            return Http::response($body, 200, ['Content-Type' => $type, 'ETag' => $etag]);
        };

        return match (true) {
            $path === '/robots.txt' => Http::response('', 404),
            $path === '/feed.xml' && $state['feed'] === 'rss' => $serve(acquisitionFixture('synthetic/rss-basic')['body'], 'application/rss+xml'),
            $path === '/feed.xml' && $state['feed'] === 'empty' => $serve(acquisitionFixture('synthetic/rss-empty')['body'], 'application/rss+xml'),
            $path === '/feed.xml' && $state['feed'] === 'html' => $serve('<html><body><main><p>Please enable JavaScript</p></main></body></html>', 'text/html'),
            $path === '/sitemap.xml' => $serve(acquisitionFixture('synthetic/sitemap-index')['body'], 'application/xml'),
            $path === '/sitemap-news.xml' => $serve(acquisitionFixture('synthetic/sitemap-basic')['body'], 'application/xml'),
            $path === '/news/simple-article' && $state['article'] === 'v1' => $serve(acquisitionFixture('synthetic/simple-article')['body'], 'text/html; charset=utf-8'),
            $path === '/news/simple-article' && $state['article'] === 'v2' => $serve(str_replace('twelve performers', 'twenty performers', acquisitionFixture('synthetic/simple-article')['body']), 'text/html; charset=utf-8'),
            $path === '/news/simple-article' && $state['article'] === 'challenge' => $serve(acquisitionFixture('synthetic/empty-page')['body'], 'text/html'),
            // The article links a PDF that no feed, sitemap or index lists (NEDO press releases do this).
            $path === '/news/simple-article' && $state['article'] === 'with-attachment' => $serve(str_replace('</article>', '<p><a href="/files/attachment.pdf">Attachment (PDF)</a></p></article>', acquisitionFixture('synthetic/simple-article')['body']), 'text/html; charset=utf-8'),
            $path === '/files/attachment.pdf' => $serve(acquisitionFixture('synthetic/pdf-text')['body'], 'application/pdf'),
            $path === '/news/older-article' && $state['older'] === 'article' => $serve(str_replace(['simple-article', 'New Research Program'], ['older-article', 'Older Program'], acquisitionFixture('synthetic/simple-article')['body']), 'text/html; charset=utf-8'),
            // The same page without its meta / JSON-LD dates: only the <time> element in the text remains.
            $path === '/news/older-article' && $state['older'] === 'undated' => $serve(str_replace(['simple-article', 'New Research Program', '<meta property="article:published_time" content="2026-09-20T09:00:00Z">', '"datePublished":"2026-09-20T09:00:00Z",'], ['older-article', 'Older Program', '', ''], acquisitionFixture('synthetic/simple-article')['body']), 'text/html; charset=utf-8'),
            $path === '/news/older-article' => $serve(acquisitionFixture('synthetic/empty-page')['body'], 'text/html'),
            $path === '/files/fixture-parsing-baa.pdf' => $serve(acquisitionFixture('synthetic/pdf-text')['body'], 'application/pdf'),
            default => Http::response('not found', 404),
        };
    });
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('acquisition');
    Queue::fake();
    $this->siteRegistered = false;
    $this->runner = app(MonitoringRunner::class);
    $this->source = Source::factory()->create(['key' => 'example', 'base_url' => 'https://www.example.org/']);
    $profile = json_decode((string) file_get_contents(base_path('tests/Fixtures/Acquisition/profiles/example.v1.json')), true);
    $profile['parser_bindings']['application/pdf'] = 'pdf.text@1';
    $profile['quality_expectations']['minimum_text_characters'] = 100;
    SourceProfile::factory()->for($this->source)->create(['version' => 1, 'profile_json' => $profile]);
    app(ProfileApproval::class)->approve($this->source, 1, 'tests');
    $this->source->refresh();
});

/**
 * Prepare and execute one monitoring run, returning the refreshed run.
 */
function monitorOnce(): AcquisitionRun
{
    $run = test()->runner->prepare(test()->source->refresh());
    test()->runner->execute($run);

    return $run->refresh();
}

// AT-16 slice: entrypoints -> candidates -> documents -> RAW/NORMALIZED/revisions.
it('monitors the active profile entrypoints and ingests only documents matching the patterns', function () {
    fakeMonitoredSite();

    $run = monitorOnce();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->counters)->toMatchArray(['new' => 3, 'revised' => 0, 'unchanged' => 0, 'failed' => 0, 'quality_failed' => 0])
        ->and(Document::query()->pluck('stable_key')->sort()->values()->all())->toBe([
            'guid:tag:example.org,2026:news/1001',
            'url:https://www.example.org/feed.xml',
            'url:https://www.example.org/files/fixture-parsing-baa.pdf',
            'url:https://www.example.org/news/older-article',
            'url:https://www.example.org/sitemap-news.xml',
            'url:https://www.example.org/sitemap.xml',
        ])
        ->and(Document::query()->where('stable_key', 'guid:tag:example.org,2026:news/1001')->sole()->document_type)->toBe('news')
        ->and(Document::query()->where('stable_key', 'url:https://www.example.org/files/fixture-parsing-baa.pdf')->sole()->document_type)->toBe('report')
        ->and($this->source->refresh()->health_status)->toBe(HealthStatus::Healthy);

    // /news/ (from the sitemap) matches no document pattern and is never fetched.
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/news/'));
});

// Attachments: a document's own links that match a pattern are one allowed hop when max_depth >= 2.
it('fetches documents attached to an ingested document when the profile allows a second hop', function () {
    fakeMonitoredSite(['article' => 'with-attachment']);

    $run = monitorOnce();

    expect($run->counters)->toMatchArray(['new' => 4, 'failed' => 0])
        ->and(Document::query()->where('stable_key', 'url:https://www.example.org/files/attachment.pdf')->sole()->document_type)->toBe('report');
});

// On a large backlog the listed candidates alone would use the whole budget (NEDO run #15 fetched no PDF).
it('fetches attachments right after their document instead of after the whole budget', function () {
    $profile = $this->source->activeProfile()->sole()->profile_json;
    $profile['crawl_policy']['max_urls_per_run'] = 2;
    SourceProfile::factory()->for($this->source)->create(['version' => 2, 'profile_json' => $profile]);
    app(ProfileApproval::class)->approve($this->source, 2, 'tests');
    fakeMonitoredSite(['article' => 'with-attachment']);

    $run = monitorOnce();

    // The article (first listed candidate) and its attachment fill the budget; the other two listed candidates wait.
    expect($run->counters)->toMatchArray(['fetched' => 2, 'new' => 2, 'skipped' => 2])
        ->and(Document::query()->where('stable_key', 'url:https://www.example.org/files/attachment.pdf')->exists())->toBeTrue();
});

it('does not follow attachments when max_depth is 1', function () {
    $profile = $this->source->activeProfile()->sole()->profile_json;
    $profile['crawl_policy']['max_depth'] = 1;
    SourceProfile::factory()->for($this->source)->create(['version' => 2, 'profile_json' => $profile]);
    app(ProfileApproval::class)->approve($this->source, 2, 'tests');
    fakeMonitoredSite(['article' => 'with-attachment']);

    $run = monitorOnce();

    expect($run->counters['new'])->toBe(3)
        ->and(Document::query()->where('stable_key', 'url:https://www.example.org/files/attachment.pdf')->exists())->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/files/attachment.pdf'));
});

// A sitemap <lastmod> is a modification time, not a publication date (NEDO lists pages whose printed date is older).
it('does not turn a sitemap lastmod into the published date of a document', function () {
    fakeMonitoredSite(['older' => 'undated']);

    monitorOnce();

    // older-article is listed only by the sitemap (lastmod 2026-08-01); its page keeps a <time> of 2026-11-01.
    $document = Document::query()->where('stable_key', 'url:https://www.example.org/news/older-article')->sole();
    $artifact = NormalizedArtifact::query()->where('revision_id', $document->revisions()->latest('revision_no')->sole()->id)->sole();
    $markdown = app(BlobStore::class)->get($artifact->blob_uri);

    expect($markdown)->toContain('published_at: "2026-11-01T00:00:00Z"')
        ->and($markdown)->not->toContain('2026-08-01');
});

// AT-05: the second run is idempotent — conditional GETs, no new RAW, no new revisions, observations still recorded.
it('is idempotent on a second run: conditional GETs, nothing new stored, observations kept', function () {
    fakeMonitoredSite();
    monitorOnce();
    $raws = RawArtifact::query()->count();
    $revisions = DocumentRevision::query()->count();
    $normalized = NormalizedArtifact::query()->count();
    $observations = FetchObservation::query()->count();

    $second = monitorOnce();

    expect($second->status)->toBe(RunStatus::Succeeded)
        ->and($second->counters)->toMatchArray(['new' => 0, 'revised' => 0, 'unchanged' => 3, 'failed' => 0])
        ->and(RawArtifact::query()->count())->toBe($raws)
        ->and(DocumentRevision::query()->count())->toBe($revisions)
        ->and(NormalizedArtifact::query()->count())->toBe($normalized)
        ->and(FetchObservation::query()->count())->toBeGreaterThan($observations)
        ->and(FetchObservation::query()->where('run_id', $second->id)->where('status', 304)->count())->toBeGreaterThanOrEqual(4);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/feed.xml') && $request->hasHeader('If-None-Match'));
});

// AT-06: a changed article becomes revision 2 while everything else stays unchanged.
it('appends a revision when a known document changes', function () {
    fakeMonitoredSite();
    monitorOnce();

    fakeMonitoredSite(['article' => 'v2']);
    $run = monitorOnce();

    $article = Document::query()->where('stable_key', 'guid:tag:example.org,2026:news/1001')->sole();
    expect($run->counters)->toMatchArray(['revised' => 1, 'unchanged' => 2, 'new' => 0])
        ->and($article->revisions()->count())->toBe(2)
        ->and($article->revisions()->max('revision_no'))->toBe(2);
});

it('caps the documents fetched per run at the profile limit and counts the rest as skipped', function () {
    fakeMonitoredSite();
    $profile = $this->source->activeProfile()->first();
    $profile->update(['profile_json' => [...$profile->profile_json, 'crawl_policy' => [...$profile->profile_json['crawl_policy'], 'max_urls_per_run' => 1]]]);

    $run = monitorOnce();

    expect($run->counters['new'])->toBe(1)->and($run->counters['skipped'])->toBe(2);
});

it('skips disabled sources and sources behind the circuit breaker', function () {
    $this->source->update(['next_run_not_before' => now()->addHour(), 'consecutive_failures' => 2]);
    expect(fn () => $this->runner->prepare($this->source->refresh()))->toThrow(fn (MonitoringSkipped $s) => expect($s->reason)->toBe('circuit_breaker'));

    $this->source->update(['next_run_not_before' => null, 'status' => 'DISABLED']);
    expect(fn () => $this->runner->prepare($this->source->refresh()))->toThrow(fn (MonitoringSkipped $s) => expect($s->reason)->toBe('disabled'));
});

// AT-08 / AT-12: a feed that suddenly has no entries is drift, not an empty success.
it('detects an entry-count collapse against the baseline and enters PARSER_DRIFT with evidence and a queued re-discovery', function () {
    fakeMonitoredSite();
    monitorOnce();
    monitorOnce();

    fakeMonitoredSite(['feed' => 'empty']);
    $drifted = monitorOnce();
    $this->source->refresh();

    $event = $this->source->events()->where('event_type', 'PARSER_DRIFT')->sole();
    expect($drifted->status)->toBe(RunStatus::Degraded)
        ->and($this->source->health_status)->toBe(HealthStatus::ParserDrift)
        ->and($event->evidence['from'])->toBe('HEALTHY')
        ->and($event->evidence['profile_version'])->toBe(1)
        ->and(collect($event->evidence['evidence'])->firstWhere('kind', 'entry_count_collapse')['detail'])->toMatchArray(['url' => 'https://www.example.org/feed.xml', 'observed' => 0, 'baseline' => 2.0])
        ->and($event->evidence['recommended_action'])->toContain('acquisition:discover example')
        ->and(HealthObservation::query()->where('run_id', $drifted->id)->where('metric', 'entrypoint_entry_count')->where('dimension', 'https://www.example.org/feed.xml')->sole()->status)->toBe('CRITICAL')
        // The current profile is untouched (AT-13) and re-discovery was queued exactly once.
        ->and($this->source->activeProfile()->first()?->version)->toBe(1)
        ->and(AcquisitionRun::query()->where('mode', 'DISCOVERY')->count())->toBe(1);
    Queue::assertPushed(DiscoveryRun::class, 1);

    // Staying in drift does not queue another discovery.
    fakeMonitoredSite(['feed' => 'empty']);
    monitorOnce();
    Queue::assertPushed(DiscoveryRun::class, 1);
});

// AT-08: HTTP 200 shell pages fail quality and, in bulk, are drift.
it('treats a wave of quality failures as drift rather than storing empty documents as success', function () {
    fakeMonitoredSite(['article' => 'challenge', 'older' => 'challenge']);
    $profile = $this->source->activeProfile()->first();
    $profile->update(['profile_json' => [...$profile->profile_json, 'quality_expectations' => [...$profile->profile_json['quality_expectations'], 'required_fields' => ['title']]]]);
    config(['acquisition.monitoring.min_documents_for_ratio' => 2]);

    $run = monitorOnce();

    expect($run->status)->toBe(RunStatus::Degraded)
        ->and($run->counters['quality_failed'])->toBe(2)
        ->and($this->source->refresh()->health_status)->toBe(HealthStatus::Degraded)
        ->and($this->source->events()->where('event_type', 'SOURCE_DEGRADED')->sole()->evidence['evidence'][0]['kind'])->toBe('quality_collapse');
});

// AT-12 / AT-14 (self-healing safety): approval makes the next run a canary; success restores HEALTHY.
it('recovers through an approved profile and a successful canary run', function () {
    fakeMonitoredSite();
    monitorOnce();
    monitorOnce();
    fakeMonitoredSite(['feed' => 'empty']);
    monitorOnce();
    expect($this->source->refresh()->health_status)->toBe(HealthStatus::ParserDrift);

    $v2 = SourceProfile::factory()->for($this->source)->create(['version' => 2, 'profile_json' => $this->source->activeProfile()->first()->profile_json]);
    app(ProfileApproval::class)->approve($this->source, 2, 'alice');
    expect($this->source->refresh()->health_status)->toBe(HealthStatus::RecoveryPending);

    fakeMonitoredSite();
    $canary = monitorOnce();

    expect($canary->status)->toBe(RunStatus::Succeeded)
        ->and($canary->profile_id)->toBe($v2->id)
        ->and($this->source->refresh()->health_status)->toBe(HealthStatus::Healthy)
        ->and($this->source->events()->where('event_type', 'HEALTH_RECOVERED')->sole()->evidence['reason'])->toContain('Canary')
        ->and($this->source->events()->where('event_type', 'PARSER_DRIFT')->whereNull('resolved_at')->count())->toBe(0);
});

it('runs from the console and as a queued job', function () {
    fakeMonitoredSite();

    $this->artisan('acquisition:monitor', ['--all' => true])->expectsOutputToContain('health HEALTHY -> HEALTHY')->assertSuccessful();

    Source::factory()->create(['key' => 'noprofile']);
    $this->artisan('acquisition:monitor', ['--all' => true])->expectsOutputToContain('noprofile: skipped (no_active_profile)')->assertSuccessful();

    $this->artisan('acquisition:monitor', ['source' => 'example', '--queue' => true])->assertSuccessful();
    Queue::assertPushed(MonitoringRun::class, 1);

    $pending = AcquisitionRun::query()->where('status', RunStatus::Pending)->sole();
    (new MonitoringRun($pending->id))->handle($this->runner);
    (new MonitoringRun($pending->id))->handle($this->runner);
    expect($pending->refresh()->status)->toBe(RunStatus::Succeeded);
});
