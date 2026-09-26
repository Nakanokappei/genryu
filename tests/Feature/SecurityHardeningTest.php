<?php

use App\Actions\CollectFigures;
use App\Exceptions\PrivateAddressForbidden;
use App\Models\Article;
use App\Models\Material;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

// The crawler is never steered to a non-web scheme, the machine itself, a private network or the cloud's metadata address.
it('refuses to fetch non-http urls and private addresses', function (string $url) {
    Http::fake(['*' => Http::response('secret', 200)]);

    expect(fn () => Http::get($url))->toThrow(PrivateAddressForbidden::class);
    Http::assertNothingSent();
})->with([
    'ftp://example.org/file',
    'http://169.254.169.254/latest/meta-data/',
    'http://127.0.0.1:5432/',
    'http://localhost/',
    'http://10.0.0.5/',
    'http://192.168.1.1/',
    'http://[::1]/',
]);

// Public hosts are fetched as before.
it('lets public web urls through', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    expect(Http::get('https://www.example.org/robots.txt')->body())->toBe('ok');
});

// Hardening headers go out with every page.
it('sends the hardening headers', function () {
    $this->get('/media')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

// A figure or source link that is not http(s) never reaches a page.
it('leaves non-web figure urls out', function () {
    $figures = CollectFigures::from("# T\n\n![Chart](javascript:alert(1))\n\n![Photo](https://example.org/photo.png)");

    expect(array_column($figures, 'url'))->toBe(['https://example.org/photo.png']);

    $material = Material::factory()->create(['parts' => ['angle' => 'a', 'change' => 'c', 'facts' => ['f'], 'figures' => [
        ['url' => 'javascript:alert(1)', 'alt' => 'x', 'caption' => null],
        ['url' => 'https://example.org/photo.png', 'alt' => 'y', 'caption' => null],
    ]]]);

    expect(array_column($material->figures(), 'url'))->toBe(['https://example.org/photo.png']);

    $material->document->update(['url' => 'javascript:alert(1)']);
    $article = Article::factory()->create(['material_id' => $material->id, 'figures' => [
        ['url' => 'javascript:alert(1)', 'alt' => 'x', 'caption' => null, 'section' => 'opening'],
        ['url' => 'https://example.org/photo.png', 'alt' => 'y', 'caption' => null, 'section' => 'opening'],
    ], 'body' => "Opening.\n\n## A\n\nText."]);

    expect($article->fresh()->bodyHtml())->not->toContain('javascript:')->toContain('https://example.org/photo.png');
});
