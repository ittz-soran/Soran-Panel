<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Who may sign in to the panel.
 *
 * Most of what is held here is about not being able to lock everybody out.
 * There is no /register and no forgotten-password email — the authenticator is
 * the only way back in — so an empty panel is not an inconvenience, it is a
 * database edit by hand on a server at whatever hour it is discovered.
 */
class OperatorsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->actingAs($this->me);
    }

    private function anotherAdmin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    public function test_the_screens_need_signing_in(): void
    {
        auth()->logout();

        $this->get(route('operators.index'))->assertRedirect(route('login'));
        $this->post(route('operators.store'), [])->assertRedirect(route('login'));
    }

    public function test_an_operator_can_be_added(): void
    {
        $this->post(route('operators.store'), [
            'name' => 'Karwan',
            'email' => 'karwan@soranstore.com',
            'role' => User::ROLE_STAFF,
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect(route('operators.index'));

        $added = User::where('email', 'karwan@soranstore.com')->first();

        $this->assertNotNull($added);
        $this->assertSame(User::ROLE_STAFF, $added->role);
        $this->assertTrue($added->is_active);
        $this->assertTrue(Hash::check('a-long-enough-password', $added->password));
    }

    public function test_adding_an_operator_is_logged_with_who_did_it(): void
    {
        $this->post(route('operators.store'), [
            'name' => 'Karwan', 'email' => 'karwan@soranstore.com', 'role' => 'staff',
            'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password',
        ]);

        $logged = Action::where('action', 'operator.added')->first();

        $this->assertNotNull($logged);
        $this->assertSame($this->me->id, $logged->user_id);
        $this->assertNull($logged->customer_id, 'adding an operator belongs to no customer');
        $this->assertSame('karwan@soranstore.com', $logged->detail['email']);
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->post(route('operators.store'), [
            'name' => 'Karwan', 'email' => 'karwan@soranstore.com', 'role' => 'admin',
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertSame(1, User::count());
    }

    public function test_an_email_cannot_be_used_twice(): void
    {
        $this->post(route('operators.store'), [
            'name' => 'Someone', 'email' => $this->me->email, 'role' => 'admin',
            'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password',
        ])->assertSessionHasErrors('email');
    }

    public function test_an_operator_can_be_edited_without_touching_their_password(): void
    {
        $other = $this->anotherAdmin();
        $was = $other->password;

        $this->put(route('operators.update', $other), [
            'name' => 'New Name', 'email' => $other->email, 'role' => 'admin', 'password' => '',
        ])->assertRedirect(route('operators.index'));

        $this->assertSame('New Name', $other->fresh()->name);
        $this->assertSame($was, $other->fresh()->password, 'an empty password field changed the password');
    }

    public function test_a_password_can_be_set_for_somebody(): void
    {
        $other = $this->anotherAdmin();

        $this->put(route('operators.update', $other), [
            'name' => $other->name, 'email' => $other->email, 'role' => 'admin',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertTrue(Hash::check('a-brand-new-password', $other->fresh()->password));
    }

    /** The log must never carry the password itself, set or not. */
    public function test_the_log_records_that_a_password_changed_and_not_what_to(): void
    {
        $other = $this->anotherAdmin();

        $this->put(route('operators.update', $other), [
            'name' => $other->name, 'email' => $other->email, 'role' => 'admin',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ]);

        $logged = Action::where('action', 'operator.changed')->first();

        $this->assertSame('set', $logged->detail['password']);
        $this->assertStringNotContainsString('a-brand-new-password', json_encode($logged->detail));
    }

    // ---- Not locking everybody out ----------------------------------------

    public function test_you_cannot_remove_your_own_account(): void
    {
        $this->anotherAdmin();

        $this->delete(route('operators.destroy', $this->me))->assertSessionHas('warning');

        $this->assertNotNull($this->me->fresh());
    }

    public function test_you_cannot_switch_off_your_own_account(): void
    {
        $this->anotherAdmin();

        $this->post(route('operators.deactivate', $this->me), ['active' => 0])->assertSessionHas('warning');

        $this->assertTrue($this->me->fresh()->is_active);
    }

    public function test_you_cannot_take_away_your_own_admin_role(): void
    {
        $this->anotherAdmin();

        $this->put(route('operators.update', $this->me), [
            'name' => $this->me->name, 'email' => $this->me->email, 'role' => 'staff',
        ])->assertSessionHas('warning');

        $this->assertTrue($this->me->fresh()->isAdmin());
    }

    /**
     * The one that would really hurt: the last admin removed by somebody else,
     * leaving a panel nobody can sign in to and no way to make an account.
     */
    public function test_the_last_admin_cannot_be_removed(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        $this->actingAs($staff);

        $this->delete(route('operators.destroy', $this->me))->assertSessionHas('warning');

        $this->assertNotNull($this->me->fresh());
        $this->assertSame(1, User::where('role', 'admin')->where('is_active', true)->count());
    }

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        $this->actingAs($staff);

        $this->put(route('operators.update', $this->me), [
            'name' => $this->me->name, 'email' => $this->me->email, 'role' => 'staff',
        ])->assertSessionHas('warning');

        $this->assertTrue($this->me->fresh()->isAdmin());
    }

    public function test_the_last_admin_cannot_be_switched_off(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        $this->actingAs($staff);

        $this->post(route('operators.deactivate', $this->me), ['active' => 0])->assertSessionHas('warning');

        $this->assertTrue($this->me->fresh()->is_active);
    }

    /** With a second admin there, the first may go. */
    public function test_an_admin_can_be_removed_when_another_one_remains(): void
    {
        $other = $this->anotherAdmin();

        $this->delete(route('operators.destroy', $other))->assertRedirect(route('operators.index'));

        $this->assertNull(User::find($other->id));
        $this->assertNotNull(User::withTrashed()->find($other->id), 'their name must survive on what they did');
    }

    public function test_a_removed_operator_can_be_brought_back(): void
    {
        $other = $this->anotherAdmin();
        $other->delete();

        $this->post(route('operators.restore', $other->id))->assertSessionHas('success');

        $this->assertNotNull(User::find($other->id));
    }

    /** Their name stays on what they did — Section 1, rule 2. */
    public function test_removing_an_operator_leaves_what_they_did_on_record(): void
    {
        $other = $this->anotherAdmin();
        Action::create(['user_id' => $other->id, 'action' => 'storage_limit.changed', 'created_at' => now()]);

        $this->delete(route('operators.destroy', $other));

        $this->assertSame($other->id, Action::where('action', 'storage_limit.changed')->first()->user_id);
    }

    // ---- The lost phone ---------------------------------------------------

    public function test_an_authenticator_can_be_cleared_for_somebody_who_lost_their_phone(): void
    {
        $other = $this->anotherAdmin();
        $other->forceFill([
            'two_factor_secret' => 'a-secret',
            'two_factor_recovery_codes' => ['AAAAA-BBBBB'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->assertTrue($other->fresh()->hasAuthenticator());

        $this->post(route('operators.authenticator', $other))->assertSessionHas('warning');

        $other = $other->fresh();

        $this->assertFalse($other->hasAuthenticator());
        $this->assertNull($other->two_factor_secret);
        $this->assertNull($other->two_factor_recovery_codes);
    }

    /** Clearing somebody's second factor is what an attacker would want to do. */
    public function test_clearing_an_authenticator_is_logged(): void
    {
        $other = $this->anotherAdmin();

        $this->post(route('operators.authenticator', $other));

        $logged = Action::where('action', 'operator.authenticator_reset')->first();

        $this->assertNotNull($logged);
        $this->assertSame($this->me->id, $logged->user_id);
        $this->assertSame($other->email, $logged->detail['who']);
    }

    // ---- The screen -------------------------------------------------------

    public function test_the_list_warns_when_there_is_only_one_admin(): void
    {
        $this->get(route('operators.index'))
            ->assertOk()
            ->assertSee('There is only one admin');
    }

    public function test_the_warning_goes_once_there_are_two(): void
    {
        $this->anotherAdmin();

        $this->get(route('operators.index'))
            ->assertOk()
            ->assertDontSee('There is only one admin');
    }

    public function test_the_list_shows_who_has_an_authenticator(): void
    {
        $this->get(route('operators.index'))
            ->assertOk()
            ->assertSee('Authenticator')
            ->assertSee($this->me->email);
    }
}
