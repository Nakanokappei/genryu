<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        // The home of the site is the media site it makes.
        $this->get(route('home'))->assertRedirect('/media');
        $this->get('/media')->assertOk();
    }
}
