<?php

namespace App\Console\Commands;

use App\Acquisition\Domain\Models\Source;
use Illuminate\Console\Command;

/**
 * acquisition:source — register and list monitored sources. Registration
 * is deliberately manual: adding a source is a human decision (plan §3.8).
 */
class AcquisitionSource extends Command
{
    protected $signature = 'acquisition:source
        {action : add | list}
        {key? : Source key, e.g. darpa}
        {name? : Display name}
        {base_url? : Official base URL}';

    protected $description = 'Register (add) or list monitored sources';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'add' => $this->add(),
            'list' => $this->list(),
            default => $this->invalid("Unknown action {$this->argument('action')}; use add or list."),
        };
    }

    private function add(): int
    {
        $key = (string) $this->argument('key');
        $name = (string) $this->argument('name');
        $baseUrl = (string) $this->argument('base_url');

        if (preg_match('/^[a-z0-9_-]+$/', $key) !== 1 || $name === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            return $this->invalid('Usage: acquisition:source add <key: [a-z0-9_-]+> <name> <https://base.url/>');
        }

        if (Source::query()->where('key', $key)->exists()) {
            return $this->invalid("Source {$key} already exists.");
        }

        $source = Source::query()->create(['key' => $key, 'name' => $name, 'base_url' => $baseUrl]);
        $this->info("Registered source {$source->key} (#{$source->id}).");

        return self::SUCCESS;
    }

    private function list(): int
    {
        $rows = Source::query()->orderBy('key')->get()->map(fn (Source $source): array => [
            $source->key, $source->name, $source->base_url, $source->status->value, $source->health_status->value,
            $source->activeProfile()->first()->version ?? '-', $source->consecutive_failures, $source->next_run_not_before?->toIso8601ZuluString() ?? '-',
        ])->all();

        $this->table(['key', 'name', 'base_url', 'status', 'health', 'active profile', 'failures', 'blocked until'], $rows);

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
