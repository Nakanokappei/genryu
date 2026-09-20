<?php

use App\Acquisition\Domain\Models\AppendOnlyViolation;
use App\Acquisition\Domain\Models\RawArtifact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

// AT-03: RAW artifacts have no update path, in code or in schema.
it('refuses to update a stored RAW artifact', function () {
    $raw = RawArtifact::factory()->create();

    expect(fn () => $raw->update(['media_type' => 'text/plain']))->toThrow(AppendOnlyViolation::class)
        ->and($raw->fresh()?->media_type)->toBe('text/html');
});

it('refuses to delete a stored RAW artifact', function () {
    $raw = RawArtifact::factory()->create();

    expect(fn () => $raw->delete())->toThrow(AppendOnlyViolation::class)
        ->and(RawArtifact::query()->whereKey($raw->id)->exists())->toBeTrue();
});

it('rejects a second row for the same content hash', function () {
    $raw = RawArtifact::factory()->create();

    expect(fn () => RawArtifact::factory()->create(['sha256' => $raw->sha256]))->toThrow(QueryException::class);
});

it('has no updated_at column', function () {
    expect(Schema::hasColumn('raw_artifacts', 'updated_at'))->toBeFalse();
});
