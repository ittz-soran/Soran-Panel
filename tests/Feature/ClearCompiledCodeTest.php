<?php

namespace Tests\Feature;

use App\Contracts\ShopWriter;
use App\Models\Action;
use App\Models\Customer;
use App\Models\User;
use App\Services\ShopControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Throwing away what a shop compiled from the shared code.
 *
 * Section 3 gives every shop its own `bootstrap/cache`, so each one holds its
 * own compiled copy of code that is shared and can change underneath it. That
 * already happens automatically when the shop system is updated; what this
 * covers is doing it on purpose, when the update is not the reason.
 *
 * ⚠️ It is also the ONLY command the panel will run on a shop for its own sake.
 * A box that runs any artisan command on a customer's install is remote code
 * execution on somebody else's shop, reachable by whoever gets into this panel.
 * `ShopControls::clearCompiledCode` says so where somebody adding the next
 * button will read it.
 */
class ClearCompiledCodeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> the customers whose artisan was actually run */
    private array $cleared = [];

    /** Shops the fake writer refuses, standing in for a folder that has gone. */
    private array $stubborn = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $cleared = &$this->cleared;
        $stubborn = &$this->stubborn;

        $this->swap(ShopWriter::class, new class($cleared, $stubborn) implements ShopWriter
        {
            public function __construct(public array &$cleared, public array &$stubborn) {}

            public function putEnv(Customer $customer, array $set, array $remove = [], array $removeIfBlank = []): void {}

            public function clearCache(Customer $customer): bool
            {
                if (in_array($customer->id, $this->stubborn, true)) {
                    return false;
                }

                $this->cleared[] = $customer->id;

                return true;
            }
        });
    }

    private function controls(): ShopControls
    {
        return app(ShopControls::class);
    }

    public function test_one_shop_is_cleared_and_the_answer_says_what_that_means(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bazaar']);

        $result = $this->controls()->clearCompiledCode($customer);

        $this->assertTrue($result['ok']);
        $this->assertSame([$customer->id], $this->cleared);
        $this->assertStringContainsString('Bazaar', $result['said']);
    }

    /** A shop whose folder has gone cannot be cleared, and must say so. */
    public function test_a_shop_that_cannot_be_cleared_is_reported_rather_than_reported_as_done(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bazaar']);
        $this->stubborn = [$customer->id];

        $result = $this->controls()->clearCompiledCode($customer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not be cleared', $result['said']);
    }

    public function test_it_is_written_down(): void
    {
        $customer = Customer::factory()->create();

        $this->controls()->clearCompiledCode($customer);

        $action = Action::where('action', 'shop.cache_cleared')->firstOrFail();

        $this->assertSame('Soran', $action->user->name);
        $this->assertTrue($action->detail['ok']);
    }

    // ---------------------------------------------------------------- all of them

    /** One shop that cannot be cleared must not stop the other five. */
    public function test_clearing_them_all_does_not_stop_at_the_first_failure(): void
    {
        $shops = Customer::factory()->count(3)->create();
        $this->stubborn = [$shops[1]->id];

        $done = $this->controls()->clearEveryShop();

        $this->assertSame(2, $done['cleared']);
        $this->assertSame([$shops[1]->name], $done['stubborn']);
        $this->assertSame([$shops[0]->id, $shops[2]->id], $this->cleared);
    }

    /** A removed shop has no folder to clear, and is not one of "every shop". */
    public function test_a_removed_shop_is_left_out(): void
    {
        $live = Customer::factory()->create();
        $gone = Customer::factory()->create();
        $gone->delete();

        $done = $this->controls()->clearEveryShop();

        $this->assertSame(1, $done['cleared']);
        $this->assertSame([$live->id], $this->cleared);
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_button_on_a_shops_page_clears_that_shop(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bazaar']);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Throw away what it compiled');

        $this->post(route('customers.clear', $customer))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $said) => str_contains($said, 'Bazaar'));

        $this->assertSame([$customer->id], $this->cleared);
    }

    public function test_the_updates_screen_can_clear_all_of_them(): void
    {
        Customer::factory()->count(2)->create();

        $this->get(route('updates'))
            ->assertOk()
            ->assertSee('Clear every shop’s compiled code', escape: false);

        $this->post(route('updates.clear-shops'))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $said) => str_contains($said, '2 shops'));

        $this->assertCount(2, $this->cleared);
    }

    public function test_clearing_them_all_when_there_are_none_says_so(): void
    {
        $this->post(route('updates.clear-shops'))
            ->assertSessionHas('warning', fn (string $said) => str_contains($said, 'no shops'));
    }

    public function test_both_are_behind_the_sign_in(): void
    {
        $customer = Customer::factory()->create();

        auth()->logout();

        $this->post(route('customers.clear', $customer))->assertRedirect(route('login'));
        $this->post(route('updates.clear-shops'))->assertRedirect(route('login'));
    }

    /**
     * The panel offers named things, never a command to type.
     *
     * Nothing in the panel builds an artisan command out of anything a person
     * typed, and this fails if that ever changes — the check is cheap and the
     * hole it guards is the largest one this codebase could grow.
     */
    public function test_nothing_lets_an_operator_choose_the_command(): void
    {
        $suspicious = [];

        foreach ((array) glob(app_path('{Services,Http/Controllers}/*.php'), GLOB_BRACE) as $file) {
            $code = (string) file_get_contents($file);

            // A Process built from request input, however indirectly.
            if (preg_match('/new Process\(\[[^]]*\$(request|fields|input|command)\b/', $code) === 1) {
                $suspicious[] = basename($file);
            }
        }

        $this->assertSame([], $suspicious,
            'something builds a command from what an operator typed: '.implode(', ', $suspicious));
    }
}
