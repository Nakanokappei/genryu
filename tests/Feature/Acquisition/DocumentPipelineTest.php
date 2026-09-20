<?php

use App\Acquisition\Application\DocumentPipeline;
use App\Acquisition\Application\IngestOutcome;
use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Document;
use App\Acquisition\Domain\Models\FetchObservation;
use App\Acquisition\Domain\Models\NormalizedArtifact;
use App\Acquisition\Domain\Models\RawArtifact;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use App\Acquisition\Tools\ToolError;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('acquisition');
    $this->pipeline = app(DocumentPipeline::class);
    $this->source = Source::factory()->create(['key' => 'example']);
    $this->run = AcquisitionRun::factory()->for($this->source)->create();
});

// AT-05: the same bytes twice produce one RAW, one revision, two observations.
it('ingests a page as NEW, then as UNCHANGED when the bytes repeat', function () {
    $first = $this->pipeline->ingest($this->run, $this->source, null, fakeFetchResult('synthetic/simple-article'), null, 'news');
    $second = $this->pipeline->ingest($this->run, $this->source, null, fakeFetchResult('synthetic/simple-article'), null, 'news');

    expect($first->change)->toBe(IngestOutcome::NEW)
        ->and($first->qualityPassed)->toBeTrue()
        ->and($first->document->stable_key)->toBe('url:https://www.example.org/news/simple-article')
        ->and($first->document->document_type)->toBe('news')
        ->and($first->revision->revision_no)->toBe(1)
        ->and($second->change)->toBe(IngestOutcome::UNCHANGED)
        ->and($second->revision->id)->toBe($first->revision->id)
        ->and(RawArtifact::query()->count())->toBe(1)
        ->and(Document::query()->count())->toBe(1)
        ->and(NormalizedArtifact::query()->count())->toBe(1)
        ->and(FetchObservation::query()->where('raw_artifact_id', $first->raw->id)->count())->toBe(2)
        // The requested URL only differed by tracking noise, which normalizes away: no alias needed.
        ->and($first->document->aliases()->count())->toBe(0);
});

// AT-06: changed bytes append revision 2 and a second NORMALIZED artifact.
it('appends a revision when the content changes', function () {
    $original = acquisitionFixture('synthetic/simple-article')['body'];
    $changed = str_replace('fund up to twelve performers', 'fund up to twenty performers', $original);

    $first = $this->pipeline->ingest($this->run, $this->source, null, fakeFetchResult('synthetic/simple-article'), null, 'news');
    $second = $this->pipeline->ingest($this->run, $this->source, null, fakeFetchResult('synthetic/simple-article', $changed), null, 'news');

    expect($second->change)->toBe(IngestOutcome::REVISED)
        ->and($second->document->id)->toBe($first->document->id)
        ->and($second->revision->revision_no)->toBe(2)
        ->and($first->document->revisions()->count())->toBe(2)
        ->and(NormalizedArtifact::query()->count())->toBe(2)
        ->and(RawArtifact::query()->count())->toBe(2);
});

it('ingests a feed as its own document', function () {
    $outcome = $this->pipeline->ingest($this->run, $this->source, null, fakeFetchResult('synthetic/rss-basic'));

    expect($outcome->change)->toBe(IngestOutcome::NEW)
        ->and($outcome->document->document_type)->toBe('rss')
        ->and($outcome->document->stable_key)->toBe('url:https://www.example.org/feed.xml');
});

// Plan §3.4: the original is kept even when interpretation fails.
it('keeps the RAW artifact and records the failure when parsing fails', function () {
    try {
        $this->pipeline->ingest($this->run, $this->source, null, fakeFetchResult('synthetic/malformed-xml'));
        $this->fail('expected ToolError');
    } catch (ToolError $error) {
        expect($error->errorCode)->toBe(ErrorCode::ParseFailed);
    }

    $observation = FetchObservation::query()->sole();
    expect(RawArtifact::query()->count())->toBe(1)
        ->and($observation->error_code)->toBe('PARSE_FAILED')
        ->and($observation->raw_artifact_id)->not->toBeNull()
        ->and(Document::query()->count())->toBe(0);
});

it('applies the active profile quality expectations', function () {
    $profile = SourceProfile::factory()->for($this->source)->active()->create(['profile_json' => [
        'schema_version' => 1,
        'quality_expectations' => ['required_fields' => ['title'], 'minimum_text_characters' => 100000, 'minimum_item_ratio_to_baseline' => 0.5],
    ]]);

    $outcome = $this->pipeline->ingest($this->run, $this->source, $profile, fakeFetchResult('synthetic/simple-article'));

    expect($outcome->qualityPassed)->toBeFalse()
        ->and($outcome->normalized->quality['required_fields_missing'])->toBe([])
        ->and(collect($outcome->normalized->warnings)->contains(fn (string $w) => str_contains($w, 'below the expected minimum of 100000')))->toBeTrue();
});
