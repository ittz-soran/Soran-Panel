<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The way back into the panel — PANEL_DOC Section 5.
 *
 * Soran locked out of the panel has nobody to telephone, and behind the panel
 * is every customer's install. So this door has to work, and it has to be the
 * only one.
 */
class RecoverPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clear('recover|soran@soranstore.com|127.0.0.1');

        parent::tearDown();
    }

    private function operator(): User
    {
        return User::factory()
            ->withAuthenticator($this->secret = Totp::secret())
            ->create(['email' => 'soran@soranstore.com']);
    }

    private string $secret;

    public function test_the_screen_renders(): void
    {
        $this->get('/recover-password')->assertOk()->assertSee('Back in with the authenticator');
    }

    public function test_six_digits_off_the_phone_set_a_new_password(): void
    {
        $user = $this->operator();

        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => Totp::at($this->secret),
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    /** For the phone that is lost, wiped, or in a pocket in another city. */
    public function test_a_recovery_code_works_once_and_is_then_spent(): void
    {
        $user = $this->operator();
        $code = $user->two_factor_recovery_codes[0];

        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('first-new-password', $user->fresh()->password));
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);

        // The same code a second time is worth nothing.
        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'second-new-password',
            'password_confirmation' => 'second-new-password',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('first-new-password', $user->fresh()->password));
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $user = $this->operator();

        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * An account with no authenticator is refused with the same message as a
     * wrong code, so this screen cannot be used to ask which addresses have an
     * account or which have a phone enrolled.
     */
    public function test_an_account_without_an_authenticator_is_refused_the_same_way(): void
    {
        $user = User::factory()->create(['email' => 'soran@soranstore.com']);

        $withoutAuthenticator = $this->post('/recover-password', [
            'email' => $user->email,
            'code' => '123456',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $noSuchAccount = $this->post('/recover-password', [
            'email' => 'nobody@soranstore.com',
            'code' => '123456',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $withoutAuthenticator->assertSessionHasErrors('code');
        $noSuchAccount->assertSessionHasErrors('code');

        $this->assertSame(
            $withoutAuthenticator->getSession()->get('errors')->first('code'),
            $noSuchAccount->getSession()->get('errors')->first('code'),
        );
    }

    /** Six digits is a million guesses. At five tries a minute it stays a door. */
    public function test_it_is_rate_limited(): void
    {
        $user = $this->operator();

        for ($try = 0; $try < 5; $try++) {
            $this->post('/recover-password', [
                'email' => $user->email,
                'code' => '000000',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ]);
        }

        // The right code, after too many wrong ones, still does not get in.
        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => Totp::at($this->secret),
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * A password reset that leaves the old sessions signed in has not locked
     * anybody out.
     */
    public function test_it_ends_the_remembered_sessions(): void
    {
        $user = $this->operator();
        $this->assertNotNull($user->remember_token);

        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => Totp::at($this->secret),
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertNull($user->fresh()->remember_token);
    }

    /**
     * Section 5: what the panel did, and who told it to.
     *
     * The one way into the panel that does not need the password left no trace
     * at all — a TODO waiting on a table that had existed for a week. There is
     * no session at this point, so the row has to be told whose it is: reading
     * `auth()->id()` here records null, and null is not a name.
     */
    public function test_recovering_a_password_is_written_down_against_the_name(): void
    {
        $user = $this->operator();

        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => Totp::at($this->secret),
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('login'));

        $action = Action::where('action', 'operator.password_recovered')->firstOrFail();

        $this->assertSame($user->id, $action->user_id, 'the log does not know who it was');
        $this->assertStringContainsString('six digits', $action->detail['how']);
    }

    /** Spending one of a finite set is worth telling apart from using the phone. */
    public function test_a_recovery_code_is_recorded_as_a_recovery_code(): void
    {
        $user = $this->operator();

        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => $user->two_factor_recovery_codes[0],
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('login'));

        $action = Action::where('action', 'operator.password_recovered')->firstOrFail();

        $this->assertStringContainsString('recovery code', $action->detail['how']);
    }

    /** Nothing is written when the attempt failed. */
    public function test_a_refused_attempt_leaves_no_row(): void
    {
        $user = $this->operator();

        $this->post('/recover-password', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('actions', ['action' => 'operator.password_recovered']);
    }
}
