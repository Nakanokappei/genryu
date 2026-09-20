<?php

use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use Illuminate\Database\QueryException;

// AT-02 / AT-13: "one ACTIVE version per source" is a database guarantee.
it('rejects a second ACTIVE profile for the same source', function () {
    $source = Source::factory()->create();
    SourceProfile::factory()->for($source)->active()->create(['version' => 1]);

    expect(fn () => SourceProfile::factory()->for($source)->active()->create(['version' => 2]))
        ->toThrow(QueryException::class);
});

it('allows a PENDING_APPROVAL candidate next to the ACTIVE version', function () {
    $source = Source::factory()->create();
    SourceProfile::factory()->for($source)->active()->create(['version' => 1]);
    SourceProfile::factory()->for($source)->create(['version' => 2]);

    expect($source->profiles()->count())->toBe(2)
        ->and($source->activeProfile()->first()?->version)->toBe(1);
});

it('allows an ACTIVE profile on each of several sources', function () {
    SourceProfile::factory()->active()->create();
    SourceProfile::factory()->active()->create();

    expect(SourceProfile::query()->where('status', ProfileStatus::Active)->count())->toBe(2);
});

it('rejects a duplicate version number within one source', function () {
    $source = Source::factory()->create();
    SourceProfile::factory()->for($source)->create(['version' => 1]);

    expect(fn () => SourceProfile::factory()->for($source)->create(['version' => 1]))
        ->toThrow(QueryException::class);
});

it('keeps superseded versions readable after a newer one becomes ACTIVE', function () {
    $source = Source::factory()->create();
    $old = SourceProfile::factory()->for($source)->active()->create(['version' => 1]);

    // The transition itself is a Milestone 2 command; here we only prove the
    // schema supports the resulting state.
    $old->update(['status' => ProfileStatus::Superseded, 'superseded_at' => now()]);
    SourceProfile::factory()->for($source)->active()->create(['version' => 2]);

    expect($source->profiles()->count())->toBe(2)
        ->and($old->fresh()?->status)->toBe(ProfileStatus::Superseded)
        ->and($source->activeProfile()->first()?->version)->toBe(2);
});
