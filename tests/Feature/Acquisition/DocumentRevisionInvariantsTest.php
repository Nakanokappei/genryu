<?php

use App\Acquisition\Domain\Models\AppendOnlyViolation;
use App\Acquisition\Domain\Models\Document;
use App\Acquisition\Domain\Models\DocumentRevision;
use App\Acquisition\Domain\Models\RawArtifact;
use Illuminate\Database\QueryException;

// AT-05: the database itself refuses a duplicate revision for unchanged content.
it('rejects a second revision with the same content hash for one document', function () {
    $document = Document::factory()->create();
    $raw = RawArtifact::factory()->create();

    DocumentRevision::factory()->for($document)->create([
        'revision_no' => 1,
        'raw_artifact_id' => $raw->id,
        'content_hash' => $raw->sha256,
    ]);

    expect(fn () => DocumentRevision::factory()->for($document)->create([
        'revision_no' => 2,
        'raw_artifact_id' => $raw->id,
        'content_hash' => $raw->sha256,
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate revision number within one document', function () {
    $document = Document::factory()->create();

    DocumentRevision::factory()->for($document)->create(['revision_no' => 1]);

    expect(fn () => DocumentRevision::factory()->for($document)->create(['revision_no' => 1]))
        ->toThrow(QueryException::class);
});

it('allows the same content hash to appear under different documents', function () {
    $raw = RawArtifact::factory()->create();

    DocumentRevision::factory()->create(['raw_artifact_id' => $raw->id, 'content_hash' => $raw->sha256]);
    DocumentRevision::factory()->create(['raw_artifact_id' => $raw->id, 'content_hash' => $raw->sha256]);

    expect(DocumentRevision::query()->where('content_hash', $raw->sha256)->count())->toBe(2);
});

// AT-06: past revisions are never edited or removed.
it('refuses to update or delete a revision', function () {
    $revision = DocumentRevision::factory()->create();

    expect(fn () => $revision->update(['revision_no' => 9]))->toThrow(AppendOnlyViolation::class)
        ->and(fn () => $revision->delete())->toThrow(AppendOnlyViolation::class);
});
