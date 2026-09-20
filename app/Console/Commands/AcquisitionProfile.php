<?php

namespace App\Console\Commands;

use App\Acquisition\Application\Profile\ProfileApproval;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * acquisition:profile — the human side of Source Profiles (ADR-0005):
 * approve, reject, roll back, list, show. Nothing else activates a profile.
 */
class AcquisitionProfile extends Command
{
    protected $signature = 'acquisition:profile
        {action : approve | reject | rollback | list | show}
        {source : Source key}
        {version? : Profile version (approve, reject, show)}
        {--by= : Who is acting (defaults to the OS user)}
        {--reason= : Reason, recorded on reject}';

    protected $description = 'Approve, reject, roll back or inspect Source Profile versions';

    public function handle(ProfileApproval $approval): int
    {
        $source = Source::query()->where('key', $this->argument('source'))->first();

        if ($source === null) {
            $this->error("Unknown source: {$this->argument('source')}");

            return self::INVALID;
        }

        $by = is_string($this->option('by')) && $this->option('by') !== '' ? $this->option('by') : (get_current_user() ?: 'operator');
        $version = $this->argument('version') !== null ? (int) $this->argument('version') : null;

        try {
            return match ($this->argument('action')) {
                'approve' => $this->approve($approval, $source, $version, $by),
                'reject' => $this->reject($approval, $source, $version, $by),
                'rollback' => $this->rollback($approval, $source, $by),
                'list' => $this->list($source),
                'show' => $this->show($source, $version),
                default => $this->invalid("Unknown action {$this->argument('action')}."),
            };
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function approve(ProfileApproval $approval, Source $source, ?int $version, string $by): int
    {
        if ($version === null) {
            return $this->invalid('approve needs a version.');
        }

        $profile = $approval->approve($source, $version, $by);
        $this->info("{$source->key} v{$profile->version} is now ACTIVE (approved by {$by}).");

        if ($source->refresh()->health_status->value === 'RECOVERY_PENDING') {
            $this->line('Source is RECOVERY_PENDING: the next monitoring run is the canary.');
        }

        return self::SUCCESS;
    }

    private function reject(ProfileApproval $approval, Source $source, ?int $version, string $by): int
    {
        if ($version === null) {
            return $this->invalid('reject needs a version.');
        }

        $reason = $this->option('reason');
        $approval->reject($source, $version, $by, is_string($reason) && $reason !== '' ? $reason : null);
        $this->info("{$source->key} v{$version} rejected.");

        return self::SUCCESS;
    }

    private function rollback(ProfileApproval $approval, Source $source, string $by): int
    {
        $profile = $approval->rollback($source, $by);
        $this->info("{$source->key} rolled back to v{$profile->version}.");

        return self::SUCCESS;
    }

    private function list(Source $source): int
    {
        $rows = ProfileApproval::history($source)->get()->map(fn (SourceProfile $profile): array => [
            $profile->version, $profile->status->value, $profile->created_at?->toIso8601ZuluString() ?? '-',
            $profile->approved_at?->toIso8601ZuluString() ?? '-', $profile->approved_by ?? '-', $profile->created_by_run_id ?? '-',
            mb_substr((string) $profile->change_reason, 0, 60),
        ])->all();

        $this->table(['version', 'status', 'created', 'approved', 'by', 'run', 'reason'], $rows);

        return self::SUCCESS;
    }

    private function show(Source $source, ?int $version): int
    {
        $profile = $version === null
            ? $source->activeProfile()->first()
            : $source->profiles()->where('version', $version)->first();

        if ($profile === null) {
            return $this->invalid($version === null ? 'No ACTIVE profile.' : "No version {$version}.");
        }

        $this->line((string) json_encode($profile->profile_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
