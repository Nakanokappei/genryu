<?php

use App\Acquisition\Agent\AcquisitionOrchestrator;
use App\Acquisition\Agent\AgentToolBridge;
use App\Acquisition\Agent\DiscoveryOutcome;
use App\Acquisition\Application\DiscoveryRun;
use App\Acquisition\Application\DiscoveryRunner;
use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Enums\RunStatus;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Infrastructure\AgentSdk\FakeOrchestrator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Storage::fake('acquisition');
    Http::fake([
        'www.example.org/robots.txt' => Http::response('', 404),
        'www.example.org/*' => Http::response('<html><head><title>Home</title></head><body><main><a href="/news/">News</a></main></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $this->source = Source::factory()->create(['key' => 'example', 'base_url' => 'https://www.example.org/']);
    $this->profile = json_decode((string) file_get_contents(base_path('tests/Fixtures/Acquisition/profiles/example.v1.json')), true);
});

/**
 * Bind a scripted fake as the orchestrator.
 *
 * @param  list<array{0: string, 1: array<string, mixed>}>  $script
 */
function useFakeAgent(array $script, ?string $crash = null): FakeOrchestrator
{
    $fake = new FakeOrchestrator(app(AgentToolBridge::class), $script, $crash);
    app()->instance(AcquisitionOrchestrator::class, $fake);

    return $fake;
}

// AT-01 through the whole run engine, with a deterministic Agent.
it('runs Discovery end to end and ends with a PENDING_APPROVAL candidate', function () {
    $fake = useFakeAgent([
        ['discover_web', ['seed_url' => 'https://www.example.org/', 'max_urls' => 3]],
        ['store_source_profile_candidate', ['profile' => $this->profile, 'change_reason' => 'initial discovery']],
    ]);

    $runner = app(DiscoveryRunner::class);
    $run = $runner->prepare($this->source, null, ['max_urls' => 5]);
    expect($run->status)->toBe(RunStatus::Pending)
        ->and($run->mode)->toBe(RunMode::Discovery)
        ->and($run->budget)->toMatchArray(['max_urls' => 5, 'allowed_hosts' => ['www.example.org', 'example.org'], 'seed_url' => 'https://www.example.org/']);

    $outcome = $runner->execute($run);
    $run->refresh();

    expect($outcome->status)->toBe(DiscoveryOutcome::COMPLETED)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->agent_metadata)->toMatchArray(['tool_calls' => 2, 'model' => 'fake', 'candidate_profile_id' => $outcome->candidateProfileId, 'orchestrator' => FakeOrchestrator::class])
        ->and($fake->calls[0]['result']['budget']['max_urls'])->toBe(3)
        ->and($this->source->profiles()->sole()->status)->toBe(ProfileStatus::PendingApproval)
        ->and($this->source->profiles()->sole()->created_by_run_id)->toBe($run->id)
        ->and($this->source->activeProfile()->exists())->toBeFalse()
        ->and($run->toolInvocations()->count())->toBe(2);
});

it('treats a Discovery without a candidate as a run with a failure', function () {
    useFakeAgent([['discover_web', ['seed_url' => 'https://www.example.org/']]]);

    $runner = app(DiscoveryRunner::class);
    $run = $runner->prepare($this->source);
    $runner->execute($run);

    expect($run->refresh()->status)->toBe(RunStatus::CompletedWithErrors)
        ->and($run->counters['failed'])->toBe(1);
});

it('fails the run when the orchestrator reports a crash, advancing the circuit breaker', function () {
    useFakeAgent([], crash: 'worker exited with code 137');

    $runner = app(DiscoveryRunner::class);
    $run = $runner->prepare($this->source);
    $runner->execute($run);

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->error_message)->toContain('code 137')
        ->and($this->source->refresh()->consecutive_failures)->toBe(1);
});

it('stops the script at the tool-call budget', function () {
    useFakeAgent([
        ['discover_web', ['seed_url' => 'https://www.example.org/']],
        ['store_source_profile_candidate', ['profile' => $this->profile]],
    ]);

    $runner = app(DiscoveryRunner::class);
    $run = $runner->prepare($this->source, null, ['max_tool_calls' => 1]);
    $outcome = $runner->execute($run);

    expect($outcome->status)->toBe(DiscoveryOutcome::BUDGET_EXHAUSTED)
        ->and($outcome->toolCalls)->toBe(1)
        ->and($this->source->profiles()->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(RunStatus::CompletedWithErrors);
});

it('drives the whole flow from the console commands', function () {
    useFakeAgent([['store_source_profile_candidate', ['profile' => $this->profile]]]);

    $this->artisan('acquisition:source', ['action' => 'add', 'key' => 'nedo', 'name' => 'NEDO', 'base_url' => 'https://www.nedo.go.jp/'])->assertSuccessful();
    $this->artisan('acquisition:source', ['action' => 'add', 'key' => 'nedo', 'name' => 'NEDO', 'base_url' => 'https://www.nedo.go.jp/'])->assertExitCode(2);
    $this->artisan('acquisition:source', ['action' => 'list'])->expectsOutputToContain('nedo')->assertSuccessful();

    $this->artisan('acquisition:discover', ['source' => 'example', '--max-urls' => 4])
        ->expectsOutputToContain('PENDING_APPROVAL')
        ->assertSuccessful();

    $this->artisan('acquisition:discover', ['source' => 'missing'])->assertExitCode(2);

    Queue::fake();
    $this->artisan('acquisition:discover', ['source' => 'example', '--queue' => true])->assertSuccessful();
    Queue::assertPushed(DiscoveryRun::class);
    expect(AcquisitionRun::query()->where('status', RunStatus::Pending)->count())->toBe(1);
});

it('ignores a redelivered job for a finished run', function () {
    useFakeAgent([['store_source_profile_candidate', ['profile' => $this->profile]]]);
    $runner = app(DiscoveryRunner::class);
    $run = $runner->prepare($this->source);

    (new DiscoveryRun($run->id))->handle($runner);
    (new DiscoveryRun($run->id))->handle($runner);

    expect($run->refresh()->status)->toBe(RunStatus::Succeeded)
        ->and($this->source->profiles()->count())->toBe(1);
});
