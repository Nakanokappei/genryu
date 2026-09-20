<?php

use App\Acquisition\Agent\AgentToolBridge;
use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\RawArtifact;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\ToolInvocation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Storage::fake('acquisition');
    Http::fake([
        'www.example.org/robots.txt' => Http::response('', 404),
        'www.example.org/news/simple-article' => Http::response(acquisitionFixture('synthetic/simple-article')['body'], 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'www.example.org/*' => Http::response('<html><body><main><p>page</p></main></body></html>', 200, ['Content-Type' => 'text/html']),
        'evil.example.net/*' => Http::response('never', 200),
    ]);

    $this->bridge = app(AgentToolBridge::class);
    $this->source = Source::factory()->create(['key' => 'example', 'base_url' => 'https://www.example.org/']);
    $this->run = AcquisitionRun::factory()->for($this->source)->create(['budget' => ['allowed_hosts' => ['www.example.org'], 'max_urls' => 2, 'max_depth' => 1, 'max_seconds' => 10, 'requests_per_minute' => 600]]);
});

// AT-14: only allowlisted tools, and only inside the run's host scope.
it('refuses tools that are not exposed to the Agent', function () {
    $response = $this->bridge->call('store_raw_artifact', ['anything' => true], $this->run);

    expect($response['ok'])->toBeFalse()
        ->and($response['error']['code'])->toBe('INVALID_INPUT')
        ->and($response['error']['details']['available'])->toBe(config('acquisition.agent_tools'))
        ->and($response['run_id'])->toBe($this->run->id)
        ->and($response['correlation_id'])->toBeString();
});

it('forces the host scope from the run budget, whatever the payload says', function () {
    $response = $this->bridge->call('fetch_url', ['url' => 'https://evil.example.net/x', 'allowed_hosts' => ['evil.example.net']], $this->run);

    expect($response['ok'])->toBeFalse()->and($response['error']['code'])->toBe('HOST_NOT_ALLOWED');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example.net'));

    $unscoped = AcquisitionRun::factory()->for($this->source)->create(['budget' => null]);
    $response = $this->bridge->call('fetch_url', ['url' => 'https://www.example.org/'], $unscoped);

    expect($response['error']['code'])->toBe('HOST_NOT_ALLOWED');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://www.example.org/');
});

it('fetches within scope, stores RAW, and hands the Agent a blob reference instead of bytes', function () {
    $response = $this->bridge->call('fetch_url', ['url' => 'https://www.example.org/news/simple-article'], $this->run);

    expect($response['ok'])->toBeTrue()
        ->and($response['result'])->not->toHaveKey('body')
        ->and($response['result']['raw_artifact']['blob_uri'])->toStartWith('acquisition://raw/')
        ->and(RawArtifact::query()->count())->toBe(1)
        ->and($this->run->fetchObservations()->count())->toBe(1)
        ->and(ToolInvocation::query()->where('run_id', $this->run->id)->where('tool', 'fetch_url')->where('outcome', 'SUCCEEDED')->exists())->toBeTrue();
});

it('parses and normalizes through blob references, reconstructing the source context itself', function () {
    $fetch = $this->bridge->call('fetch_url', ['url' => 'https://www.example.org/news/simple-article'], $this->run);
    $blobUri = $fetch['result']['raw_artifact']['blob_uri'];

    $parsed = $this->bridge->call('parse_html', ['raw_blob_uri' => $blobUri, 'url' => 'https://www.example.org/news/simple-article'], $this->run);
    expect($parsed['ok'])->toBeTrue()->and($parsed['result']['title'])->toBe('Example Agency Announces New Research Program');

    $normalized = $this->bridge->call('normalize_document', ['parsed' => $parsed['result'], 'raw_blob_uri' => $blobUri, 'document_type' => 'news'], $this->run);
    expect($normalized['ok'])->toBeTrue()
        ->and($normalized['result'])->toMatchArray([
            'source' => 'example',
            'stable_key' => 'url:https://www.example.org/news/simple-article',
            'raw_blob_uri' => $blobUri,
            'document_type' => 'news',
        ])
        ->and($normalized['result']['quality']['passed'])->toBeTrue();

    $missing = $this->bridge->call('parse_html', ['raw_blob_uri' => 'acquisition://raw/00/00/'.str_repeat('0', 64), 'url' => 'https://www.example.org/'], $this->run);
    expect($missing['error']['code'])->toBe('INVALID_INPUT');
});

// AT-02 / AT-13: the Agent can propose, never activate.
it('stores a profile candidate as PENDING_APPROVAL for the run source only', function () {
    $profile = json_decode((string) file_get_contents(base_path('tests/Fixtures/Acquisition/profiles/example.v1.json')), true);
    $profile['status'] = 'ACTIVE';
    $profile['source_key'] = 'somebody-else';

    $response = $this->bridge->call('store_source_profile_candidate', ['profile' => $profile, 'change_reason' => 'discovery'], $this->run);

    expect($response['ok'])->toBeTrue()
        ->and($response['result'])->toMatchArray(['version' => 1, 'status' => 'PENDING_APPROVAL', 'source_key' => 'example'])
        ->and($this->source->profiles()->sole()->status)->toBe(ProfileStatus::PendingApproval)
        ->and($this->source->profiles()->sole()->profile_json['source_key'])->toBe('example')
        ->and($this->source->activeProfile()->exists())->toBeFalse();

    $invalid = $this->bridge->call('store_source_profile_candidate', ['profile' => ['schema_version' => 1]], $this->run);
    expect($invalid['ok'])->toBeFalse()->and($invalid['error']['code'])->toBe('INVALID_INPUT')->and($invalid['error']['details']['violations'])->not->toBeEmpty();
});

it('lets the Agent tighten but not widen the discovery budget', function () {
    $response = $this->bridge->call('discover_web', ['seed_url' => 'https://www.example.org/', 'max_urls' => 500, 'max_depth' => 5], $this->run);

    expect($response['ok'])->toBeTrue()
        ->and($response['result']['budget']['max_urls'])->toBe(2)
        ->and($response['result']['budget']['max_depth'])->toBe(1)
        ->and($response['result']['budget']['urls_fetched'])->toBe(2);
});

it('works end to end through the acquisition:tool command with files and exit codes', function () {
    $dir = storage_path('framework/testing/bridge-'.uniqid());
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/request.json", json_encode(['url' => 'https://www.example.org/news/simple-article']));

    $this->artisan('acquisition:tool', ['tool' => 'fetch_url', '--run' => $this->run->id, '--request' => "{$dir}/request.json", '--response' => "{$dir}/response.json", '--correlation' => 'worker-1'])
        ->assertExitCode(0);
    $response = json_decode(File::get("{$dir}/response.json"), true);
    expect($response['ok'])->toBeTrue()->and($response['correlation_id'])->toBe('worker-1')->and($response['result']['raw_artifact']['sha256'])->toHaveLength(64);

    File::put("{$dir}/request.json", json_encode(['url' => 'https://evil.example.net/']));
    $this->artisan('acquisition:tool', ['tool' => 'fetch_url', '--run' => $this->run->id, '--request' => "{$dir}/request.json", '--response' => "{$dir}/response.json"])
        ->assertExitCode(1);
    expect(json_decode(File::get("{$dir}/response.json"), true)['error']['code'])->toBe('HOST_NOT_ALLOWED');

    $this->artisan('acquisition:tool', ['tool' => 'fetch_url', '--request' => "{$dir}/request.json"])->assertExitCode(2);
    $this->artisan('acquisition:tool', ['tool' => 'fetch_url', '--run' => 999999, '--request' => "{$dir}/request.json"])->assertExitCode(2);

    File::deleteDirectory($dir);
});
