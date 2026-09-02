<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\ShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The screen in front of ShopProvisioner::takeOn() — build order step 10.
 *
 * TakeOnShopTest drives the real thing against a real database. This one is
 * about the form: what it refuses before the provisioner is ever reached, and
 * that what the operator typed survives a refusal — because the usual next step
 * after one is a single field changed, and losing a pasted licence or a
 * database password to a re-type is how the wrong one gets entered.
 */
class TakeOnScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * An unticked checkbox is not submitted at all, so `null` here removes the
     * field rather than sending it empty — which is what the browser does and
     * what `$request->boolean()` is reading.
     *
     * @return array<string, mixed>
     */
    private function form(array $extra = []): array
    {
        return array_filter([
            'name' => 'Halabja Phone',
            'short_name' => 'halabja',
            'host' => 'halabja.soranstore.com',
            'database' => 'soran_halabja',
            'database_user' => 'soran_halabja',
            'database_password' => 'their-password',
            'monthly_fee' => 75000,
            'standing' => 'active',
            'backup' => '1',
            ...$extra,
        ], fn ($value) => $value !== null);
    }

    public function test_the_screen_is_behind_the_sign_in(): void
    {
        auth()->logout();

        $this->get(route('customers.take-on'))->assertRedirect(route('login'));
        $this->post(route('customers.take-on.store'), $this->form())->assertRedirect(route('login'));
    }

    /**
     * `take-on` sits above `{customer}` in the route file, or the router reads
     * it as a customer's id and the screen becomes a 404.
     */
    public function test_the_screen_opens(): void
    {
        $this->get(route('customers.take-on'))
            ->assertOk()
            ->assertSee('Take on an existing shop')
            ->assertSee('never', false);
    }

    public function test_it_hands_the_provisioner_exactly_what_was_typed(): void
    {
        $customer = Customer::factory()->create(['name' => 'Halabja Phone']);

        $this->mock(ShopProvisioner::class, function ($mock) use ($customer) {
            $mock->shouldReceive('takeOn')
                ->once()
                ->with(Mockery::on(function (array $wanted) {
                    return $wanted['database'] === 'soran_halabja'
                        && $wanted['database_password'] === 'their-password'
                        && $wanted['backup'] === true
                        && $wanted['trial'] === false
                        && $wanted['app_key'] === null;
                }))
                ->andReturn([
                    'customer' => $customer,
                    'found' => ['users' => 4, 'products' => 900, 'sales' => 12000, 'authenticators' => 0],
                    'migrations_run' => 6,
                    'warnings' => [],
                ]);
        });

        $this->post(route('customers.take-on.store'), $this->form())
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('success', fn (string $said) => str_contains($said, '4 users')
                && str_contains($said, '12000 sales')
                && str_contains($said, '6 migrations were run'));
    }

    /** Unticking it is allowed and the provisioner says so out loud. */
    public function test_the_backup_can_be_declined(): void
    {
        $customer = Customer::factory()->create();

        $this->mock(ShopProvisioner::class, function ($mock) use ($customer) {
            $mock->shouldReceive('takeOn')
                ->once()
                ->with(Mockery::on(fn (array $wanted) => $wanted['backup'] === false))
                ->andReturn([
                    'customer' => $customer,
                    'found' => ['users' => 1, 'products' => 1, 'sales' => 1, 'authenticators' => 0],
                    'migrations_run' => 0,
                    'warnings' => ['No backup was taken before migrating, because you said you already had one.'],
                ]);
        });

        $this->post(route('customers.take-on.store'), $this->form(['backup' => null]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn (string $said) => str_contains($said, 'No backup was taken'));
    }

    /**
     * The refusal that asks for their old APP_KEY is the one most likely to be
     * hit, and answering it means typing one more field — with everything else
     * still where it was, especially a pasted licence.
     */
    public function test_a_refusal_keeps_what_was_typed(): void
    {
        $this->mock(ShopProvisioner::class, function ($mock) {
            $mock->shouldReceive('takeOn')
                ->once()
                ->andThrow(new RuntimeException('2 of their staff have an authenticator … Nothing has been changed.'));
        });

        $this->from(route('customers.take-on'))
            ->post(route('customers.take-on.store'), $this->form([
                'standing' => 'licence',
                'licence' => 'a.signed.licence',
            ]))
            ->assertRedirect(route('customers.take-on'))
            ->assertSessionHas('warning', fn (string $said) => str_contains($said, 'Nothing has been changed'));

        $this->assertSame('a.signed.licence', session('_old_input.licence'));
        $this->assertSame('soran_halabja', session('_old_input.database'));
        $this->assertSame(0, Customer::count());
    }

    /** A database name is copied out of cPanel; a mistyped one must not become a folder. */
    public function test_a_database_name_that_is_not_one_is_refused(): void
    {
        $this->post(route('customers.take-on.store'), $this->form(['database' => 'soran halabja; drop']))
            ->assertSessionHasErrors('database');
    }

    public function test_a_key_that_is_not_an_app_key_is_refused_with_a_hint(): void
    {
        $this->post(route('customers.take-on.store'), $this->form(['app_key' => 'APP_KEY=base64:abc']))
            ->assertSessionHasErrors(['app_key' => 'An APP_KEY looks like `base64:` and then a long string. '
                .'Copy the whole line from the old install’s .env, after the `=`.']);
    }

    public function test_a_host_already_sold_is_refused_by_the_form(): void
    {
        Customer::factory()->create(['host' => 'halabja.soranstore.com']);

        $this->post(route('customers.take-on.store'), $this->form())
            ->assertSessionHasErrors('host');
    }

    public function test_saying_licence_without_pasting_one_is_refused(): void
    {
        $this->post(route('customers.take-on.store'), $this->form(['standing' => 'licence']))
            ->assertSessionHasErrors('licence');
    }
}
