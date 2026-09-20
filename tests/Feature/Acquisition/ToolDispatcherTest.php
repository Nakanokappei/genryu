<?php

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Enums\ToolOutcome;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\ToolInvocation;
use App\Acquisition\Tools\RequestDigest;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolDispatcher;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRegistry;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;
use Illuminate\Support\Facades\Http;

/**
 * A tool whose behaviour the test controls.
 */
function scriptedTool(string $name, Closure $behaviour): Tool
{
    return new class($name, $behaviour) implements Tool
    {
        public function __construct(private string $toolName, private Closure $behaviour) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function parseRequest(array $payload): ToolRequest
        {
            return new class($payload) implements ToolRequest
            {
                public function __construct(private array $payload) {}

                public function toArray(): array
                {
                    return $this->payload;
                }
            };
        }

        public function run(ToolRequest $request, ToolContext $context): ToolResult
        {
            $value = ($this->behaviour)($request);

            return new class($value) implements ToolResult
            {
                public function __construct(private mixed $value) {}

                public function toArray(): array
                {
                    return ['value' => $this->value];
                }
            };
        }
    };
}

beforeEach(function () {
    $this->run = AcquisitionRun::factory()->create();
    $this->context = ToolContext::forRun($this->run->id);
});

it('records a successful invocation with the request digest and no body', function () {
    $dispatcher = new ToolDispatcher(new ToolRegistry([scriptedTool('echo', fn () => 'ok')]));
    $payload = ['b' => 2, 'a' => ['body' => str_repeat('x', 10_000)]];

    $result = $dispatcher->dispatch('echo', $payload, $this->context);

    $row = ToolInvocation::query()->sole();
    expect($result->toArray())->toBe(['value' => 'ok'])
        ->and($row->tool)->toBe('echo')
        ->and($row->outcome)->toBe(ToolOutcome::Succeeded)
        ->and($row->error_code)->toBeNull()
        ->and($row->run_id)->toBe($this->run->id)
        ->and($row->correlation_id)->toBe($this->context->correlationId)
        ->and($row->request_digest)->toBe(RequestDigest::of(['a' => ['body' => str_repeat('x', 10_000)], 'b' => 2]));
});

it('records a failed invocation with its error code and rethrows the ToolError', function () {
    $dispatcher = new ToolDispatcher(new ToolRegistry([scriptedTool('boom', function () {
        throw new ToolError(ErrorCode::ParseFailed, 'nope');
    })]));

    expect(fn () => $dispatcher->dispatch('boom', [], $this->context))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::ParseFailed));

    $row = ToolInvocation::query()->sole();
    expect($row->outcome)->toBe(ToolOutcome::Failed)->and($row->error_code)->toBe('PARSE_FAILED');
});

it('converts unexpected exceptions into INTERNAL without leaking their message', function () {
    $dispatcher = new ToolDispatcher(new ToolRegistry([scriptedTool('crash', function () {
        throw new RuntimeException('secret body contents');
    })]));

    try {
        $dispatcher->dispatch('crash', [], $this->context);
        $this->fail('expected ToolError');
    } catch (ToolError $error) {
        expect($error->errorCode)->toBe(ErrorCode::Internal)
            ->and($error->getMessage())->not->toContain('secret')
            ->and($error->getPrevious()?->getMessage())->toBe('secret body contents')
            ->and($error->toArray($this->context)['error'])->toMatchArray([
                'code' => 'INTERNAL',
                'retryable' => false,
                'run_id' => $this->run->id,
                'correlation_id' => $this->context->correlationId,
            ]);
    }
});

// AT-14: only registered tools can be invoked.
it('refuses unknown tools and still records the attempt', function () {
    $dispatcher = new ToolDispatcher(new ToolRegistry([]));

    expect(fn () => $dispatcher->dispatch('drop_database', [], $this->context))
        ->toThrow(fn (ToolError $error) => expect($error->errorCode)->toBe(ErrorCode::InvalidInput));

    expect(ToolInvocation::query()->where('tool', 'drop_database')->where('outcome', 'FAILED')->exists())->toBeTrue();
});

it('exposes fetch_url through the application registry', function () {
    Http::fake(['www.example.org/*' => Http::response('hello', 200, ['Content-Type' => 'text/plain'])]);

    $result = app(ToolDispatcher::class)->dispatch('fetch_url', [
        'url' => 'https://www.example.org/',
        'allowed_hosts' => ['www.example.org'],
    ], $this->context);

    expect($result->toArray()['body_sha256'])->toBe(hash('sha256', 'hello'))
        ->and(app(ToolRegistry::class)->names())->toContain('fetch_url');
});
