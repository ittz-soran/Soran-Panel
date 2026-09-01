<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setting up the phone — PANEL_DOC Section 5, and the shop system's Section 8e.
 *
 * The thing worth holding is the middle step: nothing is written to the account
 * until a code comes back correct. A secret that was generated but never proved
 * reads as a way in, on a screen, and is not one.
 */
class AuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setup_screen_offers_a_square_and_the_letters(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile/authenticator');

        $response->assertOk()
            ->assertSee('<svg', false)          // the QR square, drawn on the server
            ->assertSee('Scan the square');

        $this->assertNotEmpty(session('authenticator.secret'));
    }

    public function test_an_abandoned_setup_leaves_nothing_on_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile/authenticator')->assertOk();

        $this->assertFalse($user->fresh()->hasAuthenticator());
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_a_correct_code_turns_it_on_and_shows_the_recovery_codes_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile/authenticator');
        $secret = session('authenticator.secret');

        $this->actingAs($user)
            ->post('/profile/authenticator', ['code' => Totp::at($secret)])
            ->assertRedirect(route('authenticator.show'))
            ->assertSessionHas('recovery_codes');

        $user->refresh();

        $this->assertTrue($user->hasAuthenticator());
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertCount(8, $user->two_factor_recovery_codes);

        // Held once, in a flash, and gone on the next request.
        $this->actingAs($user)->get('/profile/authenticator')->assertOk();
        $this->assertNull(session('recovery_codes'));
    }

    public function test_a_wrong_code_writes_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile/authenticator');

        $this->actingAs($user)
            ->post('/profile/authenticator', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasAuthenticator());
    }

    public function test_the_secret_is_encrypted_in_the_database(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile/authenticator');
        $secret = session('authenticator.secret');

        $this->actingAs($user)->post('/profile/authenticator', ['code' => Totp::at($secret)]);

        // A database dump that hands over the secret has handed over the way
        // back in, so what is stored must not be the secret itself.
        $stored = $this->getConnection()->table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, (string) $stored);
    }

    public function test_new_recovery_codes_need_the_current_password(): void
    {
        $user = User::factory()->withAuthenticator()->create();
        $before = $user->two_factor_recovery_codes;

        $this->actingAs($user)
            ->post('/profile/authenticator/codes', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertSame($before, $user->fresh()->two_factor_recovery_codes);

        $this->actingAs($user)
            ->post('/profile/authenticator/codes', ['password' => 'password'])
            ->assertSessionHas('recovery_codes');

        $this->assertNotSame($before, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_turning_it_off_needs_the_current_password(): void
    {
        $user = User::factory()->withAuthenticator()->create();

        $this->actingAs($user)
            ->delete('/profile/authenticator', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->hasAuthenticator());

        $this->actingAs($user)->delete('/profile/authenticator', ['password' => 'password']);

        $this->assertFalse($user->fresh()->hasAuthenticator());
    }

    /** Codes cannot be regenerated for an authenticator that was never set up. */
    public function test_regenerating_needs_an_authenticator_to_regenerate_for(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/authenticator/codes', ['password' => 'password'])
            ->assertNotFound();
    }
}
