<?php

use App\Actions\FetchUpdates;
use App\Actions\RobotsPolicy;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

it('follows the rules of our own group over the * group, longest match first', function () {
    Http::fake(['www.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /\n\nUser-agent: TechnologyWatch\nDisallow: /private/\nAllow: /private/press/\nDisallow: /*.pdf$\n", 200)]);
    $robots = new RobotsPolicy;

    expect($robots->allows('https://www.example.org/news'))->toBeTrue()
        ->and($robots->allows('https://www.example.org/private/board'))->toBeFalse()
        ->and($robots->allows('https://www.example.org/private/press/1'))->toBeTrue()
        ->and($robots->allows('https://www.example.org/files/report.pdf'))->toBeFalse();
    // One robots.txt read per host, cached.
    Http::assertSentCount(1);
});

it('allows everything without a robots.txt and nothing while it cannot be read', function () {
    Http::fake([
        'missing.example.org/robots.txt' => Http::response('', 404),
        'down.example.org/robots.txt' => Http::response('', 503),
    ]);
    $robots = new RobotsPolicy;

    expect($robots->allows('https://missing.example.org/anything'))->toBeTrue()
        ->and($robots->allows('https://down.example.org/anything'))->toBeFalse();
});

it('stops a fetch that robots.txt forbids, with the reason', function () {
    Http::fake([
        'www.example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /form/\n", 200),
        'www.example.org/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $source = Source::factory()->create(['url' => 'https://www.example.org/form/event.php?f=press.html']);

    expect(fn () => app(FetchUpdates::class)($source))->toThrow(RuntimeException::class, 'robots.txt');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/form/'));
});
