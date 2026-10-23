<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            return true;
        });
    }

    // An unknown address is answered as a known one is, so the form does not tell which accounts exist.
    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
        $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.invalid']);

        $unknown->assertRedirect(route('password.request'))->assertSessionHas('status', $known->getSession()->get('status'))->assertSessionHasNoErrors();
    }

    // The password-reset posts take five tries a minute from one address.
    public function test_the_password_reset_posts_are_throttled(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $try) {
            $this->post(route('password.email'), ['email' => "nobody{$try}@example.invalid"])->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'nobody6@example.invalid'])->assertTooManyRequests();
        $this->post(route('password.update'), ['token' => 'x', 'email' => 'nobody@example.invalid', 'password' => 'x', 'password_confirmation' => 'x'])->assertTooManyRequests();
    }
}
