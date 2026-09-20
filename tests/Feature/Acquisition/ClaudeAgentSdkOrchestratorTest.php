<?php

use App\Acquisition\Agent\DiscoveryOutcome;
use App\Acquisition\Agent\DiscoveryTask;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Infrastructure\AgentSdk\ClaudeAgentSdkOrchestrator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->source = Source::factory()->create(['key' => 'example', 'base_url' => 'https://www.example.org/']);
    $this->run = AcquisitionRun::factory()->for($this->source)->create();
    $this->task = new DiscoveryTask($this->run, $this->source, 'https://www.example.org/', ['www.example.org'], ['max_urls' => 10, 'max_tool_calls' => 5], 'look for feeds');
});

/**
 * Index of an option value in the worker command line.
 */
function workerArg(PendingProcess $process, string $flag): string
{
    $index = array_search($flag, $process->command, true);

    return $process->command[$index + 1];
}

// ADR-0001 contract: array command, worker cwd, JSON in/out, no env passed.
it('launches the worker the voc-triage way and reads its outcome document', function () {
    Process::fake(function (PendingProcess $process) {
        $input = json_decode(File::get(workerArg($process, '--input')), true);
        expect($input)->toMatchArray(['run_id' => $this->run->id, 'seed_url' => 'https://www.example.org/', 'allowed_hosts' => ['www.example.org'], 'model' => config('acquisition.worker.model'), 'prompt_version' => 'discovery@1'])
            ->and($input['tools'])->toBe(config('acquisition.agent_tools'))
            ->and($input['laravel']['base_path'])->toBe(base_path());

        File::put(workerArg($process, '--output'), json_encode([
            'status' => 'completed', 'candidate_profile_id' => 42, 'tool_calls' => 7, 'model' => 'claude-opus-5',
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300], 'cost_usd' => 0.12, 'summary' => 'Found an RSS feed.',
        ]));

        return Process::result(output: 'done');
    });

    $outcome = (new ClaudeAgentSdkOrchestrator)->discover($this->task);

    expect($outcome->status)->toBe(DiscoveryOutcome::COMPLETED)
        ->and($outcome->candidateProfileId)->toBe(42)
        ->and($outcome->toolCalls)->toBe(7)
        ->and($outcome->costUsd)->toBe(0.12)
        ->and($outcome->summary)->toBe('Found an RSS feed.');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === config('acquisition.worker.python')
        && array_slice($process->command, 1, 2) === ['-m', 'acquisition_agent']
        && $process->path === config('acquisition.worker.dir')
        && $process->timeout === config('acquisition.worker.timeout_seconds')
        && $process->environment === []);

    // The exchange directory is cleaned up whatever happened.
    expect(File::directories(storage_path('app/worker')))->toBeEmpty();
});

it('reports a failed outcome with the stderr tail when the worker dies without writing one', function () {
    Process::fake(fn () => Process::result(output: '', errorOutput: "progress...\nTraceback: ModuleNotFoundError: claude_agent_sdk", exitCode: 1));

    $outcome = (new ClaudeAgentSdkOrchestrator)->discover($this->task);

    expect($outcome->status)->toBe(DiscoveryOutcome::FAILED)
        ->and($outcome->error)->toContain('code 1')
        ->and($outcome->error)->toContain('ModuleNotFoundError');
});

it('passes a script path through to the worker when the run asks for script mode', function () {
    $this->run->update(['budget' => ['script' => '/tmp/calls.json']]);
    Process::fake(function (PendingProcess $process) {
        File::put(workerArg($process, '--output'), json_encode(['status' => 'completed', 'tool_calls' => 1, 'model' => 'script']));

        return Process::result();
    });

    $outcome = (new ClaudeAgentSdkOrchestrator)->discover(new DiscoveryTask($this->run->refresh(), $this->source, 'https://www.example.org/', ['www.example.org'], []));

    expect($outcome->model)->toBe('script');
    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, -2) === ['--script', '/tmp/calls.json']);
});

it('treats an unknown status in the outcome document as a failure', function () {
    Process::fake(function (PendingProcess $process) {
        File::put(workerArg($process, '--output'), json_encode(['status' => 'weird', 'error' => 'unexpected']));

        return Process::result();
    });

    expect((new ClaudeAgentSdkOrchestrator)->discover($this->task)->status)->toBe(DiscoveryOutcome::FAILED);
});
