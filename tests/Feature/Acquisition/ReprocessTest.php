<?php

use App\Acquisition\Application\DocumentPipeline;
use App\Acquisition\Application\RunCounters;
use App\Acquisition\Application\RunLifecycle;
use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Enums\RunStatus;
use App\Acquisition\Domain\Identity\StableKey;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\DocumentRevision;
use App\Acquisition\Domain\Models\NormalizedArtifact;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceEvent;
use App\Acquisition\Reprocessing\ReprocessRequest;
use App\Acquisition\Reprocessing\ReprocessRun;
use App\Acquisition\Reprocessing\ReprocessService;
use App\Acquisition\Tools\Storage\StorageTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('acquisition');
    // AT-07: reprocessing must never touch the network.
    Http::preventStrayRequests();

    $this->source = Source::factory()->create(['key' => 'example']);
    $this->seedRun = AcquisitionRun::factory()->for($this->source)->create();
    $this->storage = app(StorageTool::class);

    // A fully ingested article (already has html.generic@1 output)...
    $this->ingested = app(DocumentPipeline::class)->ingest($this->seedRun, $this->source, null, fakeFetchResult('synthetic/simple-article'), null, 'news');

    // ...and a revision that only has RAW, as if it were captured before a parser existed.
    $raw = $this->storage->storeRawArtifact(fakeFetchResult('synthetic/empty-page'));
    $document = $this->storage->upsertDocumentIdentity($this->source, new StableKey('url:https://www.example.org/news/only-raw', StableKey::RULE_URL), null, 'news', 'https://www.example.org/news/only-raw', CarbonImmutable::parse('2026-09-10T00:00:00Z'));
    $this->rawOnlyRevision = $this->storage->appendDocumentRevision($document, $raw, $this->seedRun, CarbonImmutable::parse('2026-09-10T00:00:00Z'))->revision;
});

it('plans a reprocess without writing anything', function () {
    $plan = app(ReprocessService::class)->plan(new ReprocessRequest('example', 'html.generic@1'));

    expect($plan->revisions)->toBe(2)
        ->and($plan->alreadyProcessed)->toBe(1)
        ->and($plan->toProcess)->toBe(1)
        ->and($plan->rawBytes)->toBe($this->rawOnlyRevision->rawArtifact->bytes)
        ->and(NormalizedArtifact::query()->count())->toBe(1)
        ->and(AcquisitionRun::query()->count())->toBe(1);
});

it('reprocesses stored RAW through the command, skipping revisions that already have output', function () {
    $this->artisan('acquisition:reprocess', ['--source' => 'example', '--parser' => 'html.generic@1'])
        ->expectsOutputToContain('SUCCEEDED')
        ->assertSuccessful();

    $run = AcquisitionRun::query()->where('mode', RunMode::Reprocess)->sole();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->counters)->toMatchArray(['reprocessed' => 1, 'skipped' => 1, 'failed' => 0])
        ->and($run->agent_metadata['reprocess']['parser'])->toBe('html.generic@1')
        ->and(NormalizedArtifact::query()->where('revision_id', $this->rawOnlyRevision->id)->where('produced_in_run_id', $run->id)->exists())->toBeTrue()
        ->and(NormalizedArtifact::query()->count())->toBe(2);
});

it('narrows the selection by document and by detection time', function () {
    $service = app(ReprocessService::class);

    expect($service->plan(new ReprocessRequest('example', 'html.generic@1', documentStableKey: 'url:https://www.example.org/news/only-raw'))->revisions)->toBe(1)
        ->and($service->plan(new ReprocessRequest('example', 'html.generic@1', from: CarbonImmutable::parse('2026-09-15T00:00:00Z')))->revisions)->toBe(1)
        ->and($service->plan(new ReprocessRequest('example', 'html.generic@1', to: CarbonImmutable::parse('2026-09-01T00:00:00Z')))->revisions)->toBe(0)
        ->and($service->plan(new ReprocessRequest('example', 'xml.feed@1'))->revisions)->toBe(0);
});

it('records per-document failures without hiding them in the run status', function () {
    $raw = $this->storage->storeRawArtifact(fakeFetchResult('synthetic/malformed-xml'));
    $document = $this->storage->upsertDocumentIdentity($this->source, new StableKey('url:https://www.example.org/broken.xml', StableKey::RULE_URL), null, 'feed', 'https://www.example.org/broken.xml', CarbonImmutable::now());
    $this->storage->appendDocumentRevision($document, $raw, $this->seedRun, CarbonImmutable::now());

    $this->artisan('acquisition:reprocess', ['--source' => 'example', '--parser' => 'xml.feed@1'])
        ->expectsOutputToContain('PARSE_FAILED')
        ->assertFailed();

    $run = AcquisitionRun::query()->where('mode', RunMode::Reprocess)->sole();
    expect($run->status)->toBe(RunStatus::CompletedWithErrors)
        ->and($run->counters['failed'])->toBe(1)
        ->and(SourceEvent::query()->where('event_type', 'REPROCESS_DOCUMENT_FAILED')->where('run_id', $run->id)->sole()->evidence['code'])->toBe('PARSE_FAILED');
});

it('dry-runs without creating a run or artifacts', function () {
    $this->artisan('acquisition:reprocess', ['--source' => 'example', '--parser' => 'html.generic@1', '--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertSuccessful();

    expect(AcquisitionRun::query()->count())->toBe(1)
        ->and(NormalizedArtifact::query()->count())->toBe(1);
});

it('rejects unknown parsers and sources before doing anything', function () {
    $this->artisan('acquisition:reprocess', ['--source' => 'example', '--parser' => 'html.generic@9', '--dry-run' => true])->assertExitCode(2);
    $this->artisan('acquisition:reprocess', ['--source' => 'nobody', '--parser' => 'html.generic@1'])->assertExitCode(2);
    $this->artisan('acquisition:reprocess', ['--parser' => 'html.generic@1'])->assertExitCode(2);

    expect(AcquisitionRun::query()->count())->toBe(1);
});

it('queues the run when asked and leaves it PENDING until the worker picks it up', function () {
    Queue::fake();

    $this->artisan('acquisition:reprocess', ['--source' => 'example', '--parser' => 'html.generic@1', '--queue' => true])->assertSuccessful();

    $run = AcquisitionRun::query()->where('mode', RunMode::Reprocess)->sole();
    expect($run->status)->toBe(RunStatus::Pending);
    Queue::assertPushed(ReprocessRun::class, fn (ReprocessRun $job) => $job->runId === $run->id && $job->request['parser'] === 'html.generic@1');
});

it('executes the queued job once and ignores redelivery of a finished run', function () {
    $lifecycle = app(RunLifecycle::class);
    $run = $lifecycle->start($this->source, RunMode::Reprocess);
    $job = new ReprocessRun($run->id, (new ReprocessRequest('example', 'html.generic@1'))->toArray());

    $job->handle($lifecycle, app(ReprocessService::class));
    expect($run->refresh()->status)->toBe(RunStatus::Succeeded)
        ->and($run->counters['reprocessed'])->toBe(1);

    // Redelivered: nothing changes.
    $job->handle($lifecycle, app(ReprocessService::class));
    expect(NormalizedArtifact::query()->count())->toBe(2)
        ->and($run->refresh()->counters['reprocessed'])->toBe(1);

    // A second run over the same data has nothing left to do.
    $again = $lifecycle->start($this->source, RunMode::Reprocess);
    (new ReprocessRun($again->id, (new ReprocessRequest('example', 'html.generic@1'))->toArray()))->handle($lifecycle, app(ReprocessService::class));
    expect($again->refresh()->counters)->toMatchArray(['reprocessed' => 0, 'skipped' => 2]);
});

it('keeps the old NORMALIZED artifact next to the new one and both point at the same RAW', function () {
    $lifecycle = app(RunLifecycle::class);
    $run = $lifecycle->begin($lifecycle->start($this->source, RunMode::Reprocess));
    app(ReprocessService::class)->execute(new ReprocessRequest('example', 'html.generic@1'), $run, new RunCounters);

    $revision = DocumentRevision::query()->findOrFail($this->rawOnlyRevision->id);
    $artifacts = $revision->normalizedArtifacts()->get();

    expect($artifacts)->toHaveCount(1)
        ->and($artifacts[0]->parser_id)->toBe('html.generic@1')
        ->and($revision->rawArtifact->sha256)->toBe(hash('sha256', acquisitionFixture('synthetic/empty-page')['body']))
        ->and($artifacts[0]->quality['passed'])->toBeFalse();
});
