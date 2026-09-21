<?php

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Identity\StableKey;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\DiscoveredResource;
use App\Acquisition\Domain\Models\NormalizedArtifact;
use App\Acquisition\Domain\Models\RawArtifact;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Http\FetchResult;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Normalize\SourceContext;
use App\Acquisition\Tools\Storage\StorageTool;
use App\Acquisition\Tools\ToolError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('acquisition');
    $this->storage = app(StorageTool::class);
    $this->source = Source::factory()->create(['key' => 'example']);
    $this->run = AcquisitionRun::factory()->for($this->source)->create();
    $this->now = CarbonImmutable::parse('2026-09-21T00:00:00Z');
});

/**
 * A successful FetchResult for a fixture body.
 */
function fetchResultFor(string $body, string $url = 'https://www.example.org/news/simple-article'): FetchResult
{
    return new FetchResult($url, $url, [], 200, ['content-type' => 'text/html; charset=utf-8'], 'text/html', 'text/html', false, CarbonImmutable::parse('2026-09-21T00:00:00Z'), 12, 1, $body, false);
}

// AT-03: bytes in, identical bytes out, exactly one copy.
it('stores RAW bytes once and reads them back unchanged', function () {
    $body = acquisitionFixture('synthetic/simple-article')['body'];

    $first = $this->storage->storeRawArtifact(fetchResultFor($body));
    $second = $this->storage->storeRawArtifact(fetchResultFor($body, 'https://www.example.org/news/simple-article?utm_source=x'));

    expect($second->id)->toBe($first->id)
        ->and(RawArtifact::query()->count())->toBe(1)
        ->and($first->sha256)->toBe(hash('sha256', $body))
        ->and(app(BlobStore::class)->get($first->blob_uri))->toBe($body)
        ->and($first->metadata['final_url'])->toBe('https://www.example.org/news/simple-article');
});

it('records fetch observations for successes and failures alike', function () {
    $body = 'hello';
    $raw = $this->storage->storeRawArtifact(fetchResultFor($body));

    $ok = $this->storage->recordFetchObservation($this->run, $this->source, 'https://www.example.org/a', fetchResultFor($body), $raw);
    $failed = $this->storage->recordFetchObservation($this->run, $this->source, 'https://www.example.org/b', null, null, new ToolError(ErrorCode::Timeout, 'timed out'));

    expect($ok->raw_artifact_id)->toBe($raw->id)->and($ok->status)->toBe(200)
        ->and($failed->error_code)->toBe('TIMEOUT')->and($failed->raw_artifact_id)->toBeNull()
        ->and($this->run->fetchObservations()->count())->toBe(2);
});

it('creates a document once per stable key and records other URLs as aliases', function () {
    $identity = new StableKey('guid:tag:example.org,2026:news/1001', StableKey::RULE_FEED_GUID);

    $first = $this->storage->upsertDocumentIdentity($this->source, $identity, 'https://www.example.org/news/simple-article', 'news', 'https://www.example.org/news/simple-article?utm_source=rss', $this->now);
    $second = $this->storage->upsertDocumentIdentity($this->source, $identity, 'https://www.example.org/news/simple-article', 'news', 'https://www.example.org/news/simple-article', $this->now->addDay());

    expect($second->id)->toBe($first->id)
        ->and($second->last_seen_at->toIso8601ZuluString())->toBe('2026-09-22T00:00:00Z')
        ->and($second->first_seen_at->toIso8601ZuluString())->toBe('2026-09-21T00:00:00Z')
        ->and($second->identity_rule)->toBe('feed_guid')
        ->and($second->aliases()->pluck('normalized_url')->all())->toBe(['https://www.example.org/news/simple-article']);
});

// AT-05 / AT-06: revision rules.
it('appends a revision only when the content hash changes', function () {
    $document = $this->storage->upsertDocumentIdentity($this->source, new StableKey('url:https://www.example.org/x', StableKey::RULE_URL), null, null, 'https://www.example.org/x', $this->now);
    $v1 = $this->storage->storeRawArtifact(fetchResultFor('version one'));
    $v2 = $this->storage->storeRawArtifact(fetchResultFor('version two'));

    $first = $this->storage->appendDocumentRevision($document, $v1, $this->run, $this->now);
    $again = $this->storage->appendDocumentRevision($document, $v1, $this->run, $this->now->addHour());
    $changed = $this->storage->appendDocumentRevision($document, $v2, $this->run, $this->now->addDay());

    expect($first->created)->toBeTrue()->and($first->revision->revision_no)->toBe(1)
        ->and($again->created)->toBeFalse()->and($again->revision->id)->toBe($first->revision->id)
        ->and($changed->created)->toBeTrue()->and($changed->revision->revision_no)->toBe(2)
        ->and($document->revisions()->count())->toBe(2)
        ->and($document->revisions()->where('revision_no', 1)->first()?->raw_artifact_id)->toBe($v1->id);
});

it('stores normalized artifacts per parser version and keeps them side by side', function () {
    $fixture = acquisitionFixture('synthetic/simple-article');
    $raw = $this->storage->storeRawArtifact(fetchResultFor($fixture['body']));
    $document = $this->storage->upsertDocumentIdentity($this->source, new StableKey('url:https://www.example.org/news/simple-article', StableKey::RULE_CANONICAL), null, 'news', 'https://www.example.org/news/simple-article', $this->now);
    $revision = $this->storage->appendDocumentRevision($document, $raw, $this->run, $this->now)->revision;

    $parsed = (new ParseHtmlTool)->parse($fixture['body'], 'https://www.example.org/news/simple-article');
    $normalized = (new NormalizeDocumentTool)->normalize($parsed, new SourceContext('example', 'https://www.example.org/news/simple-article', 'https://www.example.org/news/simple-article', $this->now, 'text/html', $raw->sha256, $raw->blob_uri));

    $stored = $this->storage->storeNormalizedArtifact($revision, $normalized, $this->run);
    $repeat = $this->storage->storeNormalizedArtifact($revision, $normalized, $this->run);

    expect($repeat->id)->toBe($stored->id)
        ->and($stored->sha256)->toBe($normalized->sha256())
        ->and(app(BlobStore::class)->get($stored->blob_uri))->toBe($normalized->toMarkdown())
        ->and($stored->quality['passed'])->toBeTrue();

    // A future parser version coexists with the current one (AT-07 groundwork).
    NormalizedArtifact::query()->create([
        'revision_id' => $revision->id, 'parser_id' => 'html.generic@2', 'normalizer_version' => $normalized->normalizerVersion,
        'blob_uri' => 'acquisition://normalized/00/00/'.str_repeat('0', 64), 'sha256' => str_repeat('0', 64),
    ]);

    expect($revision->normalizedArtifacts()->count())->toBe(2);
});

it('records discovered resources once per source with first and last seen runs', function () {
    $laterRun = AcquisitionRun::factory()->for($this->source)->create();

    $new = $this->storage->storeDiscoveryResult($this->run, $this->source, [
        ['url' => 'https://www.example.org/feed.xml', 'relation' => 'feed', 'media_type' => 'application/rss+xml', 'depth' => 1],
        ['url' => 'https://www.example.org/news/?utm_source=x', 'relation' => 'index'],
    ], $this->now);
    $again = $this->storage->storeDiscoveryResult($laterRun, $this->source, [
        ['url' => 'https://www.example.org/news/', 'relation' => 'index'],
    ], $this->now->addDay());

    $index = DiscoveredResource::query()->where('normalized_url', 'https://www.example.org/news')->sole();
    expect($new)->toBe(2)->and($again)->toBe(0)
        ->and(DiscoveredResource::query()->count())->toBe(2)
        ->and($index->first_seen_run_id)->toBe($this->run->id)
        ->and($index->last_seen_run_id)->toBe($laterRun->id);
});

// AT-02 / AT-13: candidates are validated and never arrive as ACTIVE.
it('stores a valid profile candidate as the next PENDING_APPROVAL version', function () {
    $profile = json_decode((string) file_get_contents(base_path('tests/Fixtures/Acquisition/profiles/example.v1.json')), true);
    $profile['status'] = 'ACTIVE';
    $profile['approved_by'] = 'attacker';

    $candidate = $this->storage->storeSourceProfileCandidate($this->source, $profile, $this->run, 'initial discovery');
    $next = $this->storage->storeSourceProfileCandidate($this->source, $profile, $this->run);

    expect($candidate->version)->toBe(1)->and($next->version)->toBe(2)
        ->and($candidate->status)->toBe(ProfileStatus::PendingApproval)
        ->and($candidate->profile_json['status'])->toBe('PENDING_APPROVAL')
        ->and($candidate->profile_json['approved_by'])->toBeNull()
        ->and($candidate->profile_json['profile_version'])->toBe(1)
        ->and($candidate->created_by_run_id)->toBe($this->run->id)
        ->and($this->source->activeProfile()->exists())->toBeFalse();
});

it('rejects profile candidates that violate the schema or bind unknown parsers', function () {
    $profile = json_decode((string) file_get_contents(base_path('tests/Fixtures/Acquisition/profiles/example.v1.json')), true);
    $profile['parser_bindings']['application/pdf'] = 'pdf.ocr@9';
    $profile['crawl_policy']['max_depth'] = 99;
    $profile['unexpected'] = true;
    // Not a field the normalizer produces: requiring it would fail every document (seen in the NEDO candidate).
    $profile['quality_expectations']['required_fields'][] = 'source_url';
    unset($profile['allowed_hosts']);

    try {
        $this->storage->storeSourceProfileCandidate($this->source, $profile, $this->run);
        $this->fail('expected validation failure');
    } catch (ToolError $error) {
        expect($error->errorCode)->toBe(ErrorCode::InvalidInput)
            ->and(implode("\n", $error->details['violations']))
            ->toContain('unknown parser pdf.ocr@9')
            ->toContain('allowed_hosts')
            ->toContain('max_depth')
            ->toContain('required_fields')
            ->toContain('unexpected');
    }

    expect($this->source->profiles()->count())->toBe(0);
});

it('records health observations with their baseline', function () {
    $observation = $this->storage->recordHealthObservation($this->source, $this->run, 'fetch_success_rate', 0.95, 0.99, 'HEALTHY', $this->now);

    expect($observation->metric)->toBe('fetch_success_rate')
        ->and((float) $observation->value)->toBe(0.95)
        ->and((float) $observation->baseline)->toBe(0.99);
});
