<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_an_operator_can_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('overview', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * A disabled account fails exactly the way a wrong password does, so the
     * login screen cannot be used to ask which accounts exist.
     */
    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_signing_in_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($try = 0; $try < 5; $try++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        // The sixth is refused before the password is even looked at, so the
        // right password does not get somebody past a lockout.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        RateLimiter::clear(strtolower($user->email).'|127.0.0.1');
    }

    public function test_an_operator_can_sign_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * There is no /register route and there never will be — an account on the
     * panel reaches into every customer's install.
     */
    public function test_there_is_no_registration_route(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    /**
     * Nor a forgotten-password email: MAIL_MAILER is `log` on this hosting, so
     * it would appear to send a way back in and would not.
     */
    public function test_there_is_no_forgotten_password_email(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', [])->assertNotFound();
    }

    public function test_the_panel_is_closed_to_visitors(): void
    {
        $this->get('/overview')->assertRedirect('/login');
        $this->get('/profile/authenticator')->assertRedirect('/login');
        $this->get('/')->assertRedirect(route('overview'));
    }
}
