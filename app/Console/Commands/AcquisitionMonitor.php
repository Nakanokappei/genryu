<?php

namespace App\Console\Commands;

use App\Acquisition\Application\Monitoring\MonitoringRun;
use App\Acquisition\Application\Monitoring\MonitoringRunner;
use App\Acquisition\Application\Monitoring\MonitoringSkipped;
use App\Acquisition\Domain\Models\Source;
use Illuminate\Console\Command;
use Throwable;

/**
 * acquisition:monitor — run Monitoring Mode for one source or every source
 * (the scheduler's entry point). Sources without an ACTIVE profile or with an
 * open circuit breaker are skipped, never retried in a loop (plan §13).
 */
class AcquisitionMonitor extends Command
{
    protected $signature = 'acquisition:monitor
        {source? : Source key}
        {--all : Monitor every source}
        {--queue : Dispatch runs to the queue instead of running inline}';

    protected $description = 'Run Monitoring Mode using the ACTIVE Source Profile';

    public function handle(MonitoringRunner $runner): int
    {
        $sources = $this->option('all')
            ? Source::query()->orderBy('key')->get()
            : Source::query()->where('key', (string) $this->argument('source'))->get();

        if ($sources->isEmpty()) {
            $this->error($this->option('all') ? 'No sources registered.' : "Unknown source: {$this->argument('source')}");

            return self::INVALID;
        }

        $failed = 0;

        foreach ($sources as $source) {
            try {
                $run = $runner->prepare($source);
            } catch (MonitoringSkipped $skipped) {
                $this->line("{$source->key}: skipped ({$skipped->reason}) — {$skipped->getMessage()}");

                continue;
            }

            if ($this->option('queue')) {
                MonitoringRun::dispatch($run->id);
                $this->info("{$source->key}: queued run {$run->id}.");

                continue;
            }

            try {
                $verdict = $runner->execute($run);
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$source->key}: run {$run->id} failed: {$exception->getMessage()}");

                continue;
            }

            $run->refresh();
            $this->line(sprintf(
                '%s: run %d %s | new %d revised %d unchanged %d failed %d quality_failed %d | health %s -> %s%s',
                $source->key, $run->id, $run->status->value,
                $run->counters['new'] ?? 0, $run->counters['revised'] ?? 0, $run->counters['unchanged'] ?? 0, $run->counters['failed'] ?? 0, $run->counters['quality_failed'] ?? 0,
                $verdict->from->value, $verdict->to->value, $verdict->discoveryQueued ? ' (re-discovery queued)' : '',
            ));

            foreach ($verdict->evidence as $item) {
                $this->warn("  {$item['severity']}: {$item['kind']} ".json_encode($item['detail'], JSON_UNESCAPED_SLASHES));
            }

            if ($verdict->drift || ($run->counters['failed'] ?? 0) > 0) {
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
