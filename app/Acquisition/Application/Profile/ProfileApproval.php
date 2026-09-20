<?php

namespace App\Acquisition\Application\Profile;

use App\Acquisition\Domain\Enums\HealthStatus;
use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code that moves a profile into or out of ACTIVE (ADR-0005). Every
 * transition is recorded as a source event with who did it and from/to
 * versions; the database's partial unique index guarantees one ACTIVE.
 */
final class ProfileApproval
{
    /**
     * PENDING_APPROVAL -> ACTIVE; the previous ACTIVE becomes SUPERSEDED.
     * A source in drift moves to RECOVERY_PENDING: the next Monitoring run is
     * its canary (plan §14).
     */
    public function approve(Source $source, int $version, string $by): SourceProfile
    {
        return DB::transaction(function () use ($source, $version, $by): SourceProfile {
            $source = Source::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $candidate = $this->version($source, $version);

            if ($candidate->status !== ProfileStatus::PendingApproval) {
                throw new InvalidArgumentException("Profile {$source->key} v{$version} is {$candidate->status->value}, not PENDING_APPROVAL.");
            }

            $now = CarbonImmutable::now('UTC');
            $previous = $source->activeProfile()->first();

            if ($previous !== null) {
                $previous->update(['status' => ProfileStatus::Superseded, 'superseded_at' => $now, 'profile_json' => [...$previous->profile_json, 'status' => ProfileStatus::Superseded->value]]);
            }

            $candidate->update([
                'status' => ProfileStatus::Active,
                'approved_at' => $now,
                'approved_by' => $by,
                'profile_json' => [...$candidate->profile_json, 'status' => ProfileStatus::Active->value, 'approved_at' => $now->toIso8601ZuluString(), 'approved_by' => $by],
            ]);

            $evidence = ['from_version' => $previous?->version, 'to_version' => $candidate->version, 'by' => $by];

            $previousHealth = $source->health_status;

            if (in_array($previousHealth, [HealthStatus::ParserDrift, HealthStatus::Degraded], true)) {
                $source->update(['health_status' => HealthStatus::RecoveryPending]);
                $evidence['health'] = ['from' => $previousHealth->value, 'to' => HealthStatus::RecoveryPending->value];
            }

            $source->events()->create(['event_type' => 'PROFILE_ACTIVATED', 'severity' => 'info', 'evidence' => $evidence]);

            return $candidate->refresh();
        });
    }

    /**
     * ACTIVE -> SUPERSEDED, and the most recently superseded version becomes
     * ACTIVE again.
     */
    public function rollback(Source $source, string $by): SourceProfile
    {
        return DB::transaction(function () use ($source, $by): SourceProfile {
            $source = Source::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $active = $source->activeProfile()->first() ?? throw new InvalidArgumentException("Source {$source->key} has no ACTIVE profile to roll back.");

            /** @var SourceProfile|null $previous */
            $previous = $source->profiles()
                ->where('status', ProfileStatus::Superseded)
                ->where('version', '<', $active->version)
                ->orderByDesc('version')
                ->first();

            if ($previous === null) {
                throw new InvalidArgumentException("Source {$source->key} has no earlier version to roll back to.");
            }

            $now = CarbonImmutable::now('UTC');
            $active->update(['status' => ProfileStatus::Superseded, 'superseded_at' => $now, 'profile_json' => [...$active->profile_json, 'status' => ProfileStatus::Superseded->value]]);
            $previous->update(['status' => ProfileStatus::Active, 'superseded_at' => null, 'profile_json' => [...$previous->profile_json, 'status' => ProfileStatus::Active->value]]);

            $source->events()->create(['event_type' => 'PROFILE_ROLLED_BACK', 'severity' => 'warning', 'evidence' => ['from_version' => $active->version, 'to_version' => $previous->version, 'by' => $by]]);

            return $previous->refresh();
        });
    }

    /**
     * PENDING_APPROVAL -> REJECTED.
     */
    public function reject(Source $source, int $version, string $by, ?string $reason = null): SourceProfile
    {
        return DB::transaction(function () use ($source, $version, $by, $reason): SourceProfile {
            $candidate = $this->version($source, $version);

            if ($candidate->status !== ProfileStatus::PendingApproval) {
                throw new InvalidArgumentException("Profile {$source->key} v{$version} is {$candidate->status->value}, not PENDING_APPROVAL.");
            }

            $candidate->update(['status' => ProfileStatus::Rejected, 'rejected_at' => CarbonImmutable::now('UTC'), 'change_reason' => $reason ?? $candidate->change_reason, 'profile_json' => [...$candidate->profile_json, 'status' => ProfileStatus::Rejected->value]]);
            $source->events()->create(['event_type' => 'PROFILE_REJECTED', 'severity' => 'info', 'evidence' => ['version' => $version, 'by' => $by, 'reason' => $reason]]);

            return $candidate->refresh();
        });
    }

    private function version(Source $source, int $version): SourceProfile
    {
        return $source->profiles()->where('version', $version)->lockForUpdate()->first()
            ?? throw new InvalidArgumentException("Source {$source->key} has no profile version {$version}.");
    }

    /**
     * @return Builder<SourceProfile>
     */
    public static function history(Source $source): Builder
    {
        return $source->profiles()->getQuery()->orderBy('version');
    }
}
