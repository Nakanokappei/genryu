<?php

namespace App\Console\Commands;

use App\Acquisition\Application\RunCounters;
use App\Acquisition\Application\RunLifecycle;
use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Reprocessing\ReprocessRequest;
use App\Acquisition\Reprocessing\ReprocessRun;
use App\Acquisition\Reprocessing\ReprocessService;
use App\Acquisition\Tools\ToolError;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Throwable;

/**
 * acquisition:reprocess (plan §11): regenerate NORMALIZED artifacts from
 * stored RAW with a specific parser version, without network access.
 * Exit code 1 when any document failed, so operators and CI notice.
 */
class AcquisitionReprocess extends Command
{
    protected $signature = 'acquisition:reprocess
        {--source= : Source key, e.g. darpa}
        {--parser= : Parser ID, e.g. html.generic@1}
        {--from= : Only revisions detected at or after this time}
        {--to= : Only revisions detected at or before this time}
        {--document= : Only this document (stable key)}
        {--dry-run : Show what would be processed and write nothing}
        {--queue : Dispatch to the queue instead of running inline}';

    protected $description = 'Re-parse stored RAW artifacts with a parser version and store new NORMALIZED artifacts';

    public function handle(ReprocessService $reprocess, RunLifecycle $lifecycle): int
    {
        try {
            $request = $this->request();
        } catch (InvalidFormatException $exception) {
            $this->error('Invalid --from/--to: '.$exception->getMessage());

            return self::INVALID;
        }

        if ($request === null) {
            return self::INVALID;
        }

        $source = Source::query()->where('key', $request->sourceKey)->first();

        if ($source === null) {
            $this->error("Unknown source: {$request->sourceKey}");

            return self::INVALID;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($reprocess, $request);
        }

        $run = $lifecycle->start($source, RunMode::Reprocess, $source->activeProfile()->first(), null, ['reprocess' => $request->toArray()]);

        if ($this->option('queue')) {
            ReprocessRun::dispatch($run->id, $request->toArray());
            $this->info("Queued reprocess run {$run->id}.");

            return self::SUCCESS;
        }

        $lifecycle->begin($run);
        $counters = new RunCounters;

        try {
            $report = $reprocess->execute($request, $run, $counters);
        } catch (Throwable $exception) {
            $lifecycle->fail($run, $exception, $counters);
            $this->error("Run {$run->id} failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $lifecycle->finish($run, $counters);

        $this->table(['run', 'status', 'reprocessed', 'skipped (existing)', 'failed'], [[
            $run->id, $run->status->value, $report->reprocessed, $report->skippedExisting, $report->failed(),
        ]]);

        foreach ($report->failures as $failure) {
            $this->warn("  revision {$failure['revision_id']} ({$failure['stable_key']}): {$failure['code']} {$failure['message']}");
        }

        return $report->failed() === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function dryRun(ReprocessService $reprocess, ReprocessRequest $request): int
    {
        try {
            $plan = $reprocess->plan($request);
        } catch (ToolError $error) {
            $this->error($error->getMessage());

            return self::INVALID;
        }

        $this->table(['applicable revisions', 'already processed', 'to process', 'raw bytes to read'], [[
            $plan->revisions, $plan->alreadyProcessed, $plan->toProcess, number_format($plan->rawBytes),
        ]]);
        $this->line('Dry run: nothing was written.');

        return self::SUCCESS;
    }

    /**
     * Build the request from options, or report what is missing.
     */
    private function request(): ?ReprocessRequest
    {
        $source = (string) $this->option('source');
        $parser = (string) $this->option('parser');

        if ($source === '' || $parser === '') {
            $this->error('--source and --parser are required.');

            return null;
        }

        $from = $this->option('from');
        $to = $this->option('to');

        return new ReprocessRequest(
            sourceKey: $source,
            parserId: $parser,
            from: is_string($from) && $from !== '' ? CarbonImmutable::parse($from)->utc() : null,
            to: is_string($to) && $to !== '' ? CarbonImmutable::parse($to)->utc() : null,
            documentStableKey: is_string($this->option('document')) && $this->option('document') !== '' ? $this->option('document') : null,
        );
    }
}
