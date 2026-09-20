<?php

namespace App\Console\Commands;

use App\Acquisition\Agent\AgentToolBridge;
use App\Acquisition\Domain\Models\AcquisitionRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * acquisition:tool — the CLI bridge the Python worker calls for every Tool
 * (ADR-0001). Reads a JSON request file, writes a JSON response file, and
 * exits 0 on success or 1 when the tool reported an error, so the worker
 * can set is_error without parsing the body.
 */
class AcquisitionTool extends Command
{
    protected $signature = 'acquisition:tool
        {tool : Tool name, e.g. fetch_url}
        {--run= : The acquisition run this call belongs to}
        {--request= : Path of the JSON request file}
        {--response= : Path to write the JSON response to (stdout when omitted)}
        {--correlation= : Correlation ID supplied by the worker}';

    protected $description = 'Invoke one Agent-facing tool on behalf of a run (used by the worker)';

    protected $hidden = true;

    public function handle(AgentToolBridge $bridge): int
    {
        $runId = (int) $this->option('run');
        $requestPath = (string) $this->option('request');

        if ($runId <= 0 || $requestPath === '' || ! File::exists($requestPath)) {
            $this->error('--run and an existing --request file are required.');

            return self::INVALID;
        }

        $run = AcquisitionRun::query()->find($runId);

        if ($run === null) {
            $this->error("Unknown run {$runId}.");

            return self::INVALID;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(File::get($requestPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Request is not valid JSON: '.$exception->getMessage());

            return self::INVALID;
        }

        $correlation = $this->option('correlation');
        $response = $bridge->call((string) $this->argument('tool'), $payload, $run, is_string($correlation) && $correlation !== '' ? $correlation : null);
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $responsePath = $this->option('response');

        if (is_string($responsePath) && $responsePath !== '') {
            File::ensureDirectoryExists(dirname($responsePath));
            File::put($responsePath, $json);
        } else {
            $this->line($json);
        }

        return $response['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
