<?php

use App\Acquisition\Application\Monitoring\MonitoringRunner;
use App\Acquisition\Application\Monitoring\MonitoringSkipped;
use App\Acquisition\Application\Profile\ProfileApproval;
use App\Acquisition\Domain\Enums\HealthStatus;
use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;

beforeEach(function () {
    $this->approval = app(ProfileApproval::class);
    $this->source = Source::factory()->create(['key' => 'example']);
    $this->profile = json_decode((string) file_get_contents(base_path('tests/Fixtures/Acquisition/profiles/example.v1.json')), true);
});

// AT-02: a PENDING_APPROVAL profile is never used by Monitoring.
it('refuses to monitor a source whose only profile is pending approval', function () {
    SourceProfile::factory()->for($this->source)->create(['profile_json' => $this->profile]);

    expect(fn () => app(MonitoringRunner::class)->prepare($this->source))
        ->toThrow(fn (MonitoringSkipped $skipped) => expect($skipped->reason)->toBe('no_active_profile'));
});

it('activates a candidate only through approval, superseding the previous version with an audit trail', function () {
    $v1 = SourceProfile::factory()->for($this->source)->create(['version' => 1, 'profile_json' => $this->profile]);
    $this->approval->approve($this->source, 1, 'alice');

    $v2 = SourceProfile::factory()->for($this->source)->create(['version' => 2, 'profile_json' => [...$this->profile, 'profile_version' => 2]]);
    $this->approval->approve($this->source, 2, 'bob');

    expect($v1->refresh()->status)->toBe(ProfileStatus::Superseded)
        ->and($v1->superseded_at)->not->toBeNull()
        ->and($v1->profile_json['status'])->toBe('SUPERSEDED')
        ->and($v2->refresh()->status)->toBe(ProfileStatus::Active)
        ->and($v2->approved_by)->toBe('bob')
        ->and($v2->profile_json)->toMatchArray(['status' => 'ACTIVE', 'approved_by' => 'bob'])
        ->and($this->source->activeProfile()->first()?->version)->toBe(2)
        ->and($this->source->events()->where('event_type', 'PROFILE_ACTIVATED')->count())->toBe(2)
        ->and($this->source->events()->where('event_type', 'PROFILE_ACTIVATED')->latest('id')->first()?->evidence)->toMatchArray(['from_version' => 1, 'to_version' => 2, 'by' => 'bob']);
});

it('only approves PENDING_APPROVAL versions', function () {
    SourceProfile::factory()->for($this->source)->create(['version' => 1, 'profile_json' => $this->profile]);
    $this->approval->approve($this->source, 1, 'alice');

    expect(fn () => $this->approval->approve($this->source, 1, 'alice'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->approval->approve($this->source, 9, 'alice'))->toThrow(InvalidArgumentException::class);
});

// AT-13: rollback restores the previous version and keeps every version readable.
it('rolls back to the previous version and can roll forward again by approval', function () {
    SourceProfile::factory()->for($this->source)->create(['version' => 1, 'profile_json' => $this->profile]);
    SourceProfile::factory()->for($this->source)->create(['version' => 2, 'profile_json' => $this->profile]);
    $this->approval->approve($this->source, 1, 'alice');
    $this->approval->approve($this->source, 2, 'alice');

    $restored = $this->approval->rollback($this->source, 'carol');

    expect($restored->version)->toBe(1)
        ->and($restored->status)->toBe(ProfileStatus::Active)
        ->and($this->source->profiles()->where('version', 2)->sole()->status)->toBe(ProfileStatus::Superseded)
        ->and($this->source->profiles()->count())->toBe(2)
        ->and($this->source->events()->where('event_type', 'PROFILE_ROLLED_BACK')->sole()->evidence)->toMatchArray(['from_version' => 2, 'to_version' => 1, 'by' => 'carol']);

    expect(fn () => $this->approval->rollback($this->source, 'carol'))->toThrow(InvalidArgumentException::class);
});

it('rejects candidates with a reason and leaves ACTIVE untouched', function () {
    SourceProfile::factory()->for($this->source)->create(['version' => 1, 'profile_json' => $this->profile]);
    $this->approval->approve($this->source, 1, 'alice');
    SourceProfile::factory()->for($this->source)->create(['version' => 2, 'profile_json' => $this->profile]);

    $rejected = $this->approval->reject($this->source, 2, 'alice', 'feed URL is a redirect');

    expect($rejected->status)->toBe(ProfileStatus::Rejected)
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($rejected->change_reason)->toBe('feed URL is a redirect')
        ->and($this->source->activeProfile()->first()?->version)->toBe(1);
});

it('moves a drifting source to RECOVERY_PENDING when a new profile is approved', function () {
    $this->source->update(['health_status' => HealthStatus::ParserDrift]);
    SourceProfile::factory()->for($this->source)->create(['version' => 1, 'profile_json' => $this->profile]);

    $this->approval->approve($this->source, 1, 'alice');

    expect($this->source->refresh()->health_status)->toBe(HealthStatus::RecoveryPending);
});

it('exposes approve, reject, rollback, list and show through the console', function () {
    SourceProfile::factory()->for($this->source)->create(['version' => 1, 'profile_json' => $this->profile]);
    SourceProfile::factory()->for($this->source)->create(['version' => 2, 'profile_json' => $this->profile]);

    $this->artisan('acquisition:profile', ['action' => 'approve', 'source' => 'example', 'version' => 1, '--by' => 'alice'])->expectsOutputToContain('now ACTIVE')->assertSuccessful();
    $this->artisan('acquisition:profile', ['action' => 'approve', 'source' => 'example', 'version' => 2, '--by' => 'alice'])->assertSuccessful();
    $this->artisan('acquisition:profile', ['action' => 'rollback', 'source' => 'example', '--by' => 'alice'])->expectsOutputToContain('rolled back to v1')->assertSuccessful();
    $this->artisan('acquisition:profile', ['action' => 'rollback', 'source' => 'example'])->assertFailed();
    $this->artisan('acquisition:profile', ['action' => 'reject', 'source' => 'example', 'version' => 1])->assertFailed();
    $this->artisan('acquisition:profile', ['action' => 'list', 'source' => 'example'])->expectsOutputToContain('SUPERSEDED')->assertSuccessful();
    $this->artisan('acquisition:profile', ['action' => 'show', 'source' => 'example'])->expectsOutputToContain('"source_key": "example"')->assertSuccessful();
    $this->artisan('acquisition:profile', ['action' => 'approve', 'source' => 'example'])->assertExitCode(2);
    $this->artisan('acquisition:profile', ['action' => 'list', 'source' => 'nobody'])->assertExitCode(2);
});
