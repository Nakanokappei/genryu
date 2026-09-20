<?php

namespace App\Acquisition\Infrastructure\AgentSdk;

use App\Acquisition\Agent\AcquisitionOrchestrator;
use App\Acquisition\Agent\DiscoveryOutcome;
use App\Acquisition\Agent\DiscoveryTask;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the Python worker (Claude Agent SDK) as a child process, voc-triage
 * style (ADR-0001): array command, cwd = worker dir so worker/.env resolves,
 * JSON in and out through temp files, no environment passed. The worker
 * calls back into `php artisan acquisition:tool` for every tool.
 */
final class ClaudeAgentSdkOrchestrator implements AcquisitionOrchestrator
{
    public const PROMPT_VERSION = 'discovery@1';

    public function discover(DiscoveryTask $task): DiscoveryOutcome
    {
        /** @var array{dir: string, python: string, php: string, timeout_seconds: int, model: string} $worker */
        $worker = config('acquisition.worker');
        $exchange = storage_path('app/worker/'.Str::uuid());
        File::ensureDirectoryExists($exchange);

        $input = $exchange.'/input.json';
        $output = $exchange.'/output.json';

        try {
            File::put($input, json_encode([
                ...$task->toArray(),
                'model' => $worker['model'],
                'prompt_version' => self::PROMPT_VERSION,
                'laravel' => ['base_path' => base_path(), 'php' => $worker['php']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $command = [$worker['python'], '-m', 'acquisition_agent', '--input', $input, '--output', $output];

            // Script mode replays fixed tool calls instead of running the Agent:
            // the boundary check for the whole Laravel -> worker -> bridge chain.
            $script = $task->run->budget['script'] ?? null;

            if (is_string($script) && $script !== '') {
                array_push($command, '--script', $script);
            }

            $result = Process::path($worker['dir'])
                ->timeout($worker['timeout_seconds'])
                ->run($command);

            if (File::exists($output)) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode(File::get($output), true, 64, JSON_THROW_ON_ERROR);

                return DiscoveryOutcome::fromArray($decoded);
            }

            // No output document: the worker died before reporting. Keep only
            // the tail of stderr; the head is progress noise.
            $stderr = Str::of($result->errorOutput())->trim()->substr(-2000)->value();
            Log::channel('acquisition')->error('acquisition.worker.no_output', ['run_id' => $task->run->id, 'exit_code' => $result->exitCode(), 'stderr_tail' => $stderr]);

            return DiscoveryOutcome::failed("Worker exited with code {$result->exitCode()} without writing an outcome. ".$stderr);
        } catch (Throwable $exception) {
            Log::channel('acquisition')->error('acquisition.worker.exception', ['run_id' => $task->run->id, 'exception' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 500)]);

            return DiscoveryOutcome::failed($exception::class.': '.mb_substr($exception->getMessage(), 0, 500));
        } finally {
            File::deleteDirectory($exchange);
        }
    }
}
