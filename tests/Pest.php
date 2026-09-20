<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Acquisition unit tests need the container (Storage::fake, bindings) but no database.
pest()->extend(TestCase::class)
    ->in('Unit/Acquisition');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Load a saved HTTP response from tests/Fixtures/Acquisition.
 *
 * @return array{meta: array<string, mixed>, body: string}
 */
function acquisitionFixture(string $name): array
{
    $directory = __DIR__.'/Fixtures/Acquisition/'.$name;
    $meta = json_decode((string) file_get_contents($directory.'/response.json'), true, 512, JSON_THROW_ON_ERROR);

    return ['meta' => $meta, 'body' => (string) file_get_contents($directory.'/'.$meta['body_file'])];
}
