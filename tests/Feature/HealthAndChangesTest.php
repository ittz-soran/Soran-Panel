<?php

namespace Tests\Feature;

use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Models\Licence;
use App\Models\User;
use App\Support\Navigation;
use App\Support\ShopReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The last two Section 9 pages: Health, and What I changed.
 *
 * Both only read, and each for a stated reason. Section 8: the data check
 * "reports and does not repair — a contradiction is evidence, and repairing it
 * before it has been read destroys the record of what went wrong". Section 5:
 * `actions` has no `updated_at`, because a log somebody can edit is not a log.
 */
class HealthAndChangesTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Soran']);
        $this->actingAs($this->me);
    }

    /**
     * Just the rows.
     *
     * The whole page is the wrong thing to assert against here: the filter's
     * own dropdowns list every shop and every kind of action, so "this shop is
     * not shown" is true of the table and false of the document.
     */
    private function rows(string $body): string
    {
        preg_match('/<tbody>(.*?)<\/tbody>/s', $body, $found);

        return $found[1] ?? '';
    }

    private function shopSays(bool $reachable, array $extra = []): void
    {
        $this->swap(ShopReader::class, new class($reachable, $extra) implements ShopReader
        {
            public function __construct(private bool $reachable, private array $extra) {}

            public function read(Customer $customer): ShopReading
            {
                return new ShopReading(
                    reachable: $this->reachable,
                    productsCount: $this->extra['products'] ?? null,
                    licenceState: $this->extra['licence'] ?? null,
                    problems: $this->reachable ? [] : ['Its database would not answer.'],
                );
            }

            public function licenceState(Customer $customer): ?string
            {
                return $this->extra['licence'] ?? null;
            }
        });
    }

    // ---- Health -----------------------------------------------------------

    public function test_health_needs_signing_in(): void
    {
        auth()->logout();

        $this->get(route('health.index'))->assertRedirect(route('login'));
    }

    public function test_health_says_so_when_nothing_has_ever_been_checked(): void
    {
        Customer::factory()->create();

        $this->get(route('health.index'))
            ->assertOk()
            ->assertSee('No shop has ever been checked')
            ->assertSee('shops:check');
    }

    public function test_health_shows_each_shops_own_answers(): void
    {
        $customer = Customer::factory()->create(['name' => 'Hawler Computer']);
        HealthCheck::factory()->for($customer)->create([
            'checked_at' => now(),
            'licence_state' => 'valid',
            'migrations_run' => 28,
            'migrations_total' => 32,
            'data_check_passed' => 15,
            'data_check_total' => 17,
        ]);

        $this->get(route('health.index'))
            ->assertOk()
            ->assertSee('Hawler Computer')
            ->assertSee('valid')
            ->assertSee('4 migrations behind')
            ->assertSee('2 disagree');
    }

    /** An unreachable shop still shows the last reading that worked. */
    public function test_health_keeps_the_last_good_reading_when_a_shop_goes_down(): void
    {
        $customer = Customer::factory()->create();

        HealthCheck::factory()->for($customer)->create([
            'checked_at' => now()->subHours(2),
            'database_bytes' => 40 * 1024 * 1024,
            'backups_bytes' => 0, 'uploads_bytes' => 0,
        ]);
        HealthCheck::factory()->for($customer)->unreachable()->create(['checked_at' => now()]);

        $this->get(route('health.index'))
            ->assertOk()
            ->assertSee('could not be read')
            ->assertSee('40 MB');
    }

    public function test_health_counts_what_needs_looking_at(): void
    {
        $behind = Customer::factory()->create();
        HealthCheck::factory()->for($behind)->create([
            'checked_at' => now(), 'migrations_run' => 30, 'migrations_total' => 32,
        ]);

        $wrong = Customer::factory()->create();
        HealthCheck::factory()->for($wrong)->create([
            'checked_at' => now(), 'data_check_passed' => 16, 'data_check_total' => 17,
        ]);

        $down = Customer::factory()->create();
        HealthCheck::factory()->for($down)->unreachable()->create(['checked_at' => now()]);

        $this->get(route('health.index'))
            ->assertOk()
            ->assertSee('Unreachable')
            ->assertSee('Behind on migrations')
            ->assertSee('Contradicting themselves');
    }

    /**
     * Looking now writes another snapshot rather than replacing one. Section 5
     * keeps them as a series so storage growing over weeks stays visible.
     */
    public function test_looking_now_adds_a_reading_rather_than_replacing_one(): void
    {
        $customer = Customer::factory()->create();
        HealthCheck::factory()->for($customer)->create(['checked_at' => now()->subHour()]);

        $this->shopSays(true, ['products' => 412, 'licence' => 'valid']);

        $this->post(route('health.recheck', $customer))->assertSessionHas('success');

        $this->assertSame(2, $customer->healthChecks()->count());
        $this->assertSame(412, $customer->fresh()->latestHealthCheck->products_count);
    }

    public function test_looking_now_at_a_shop_that_will_not_answer_says_so(): void
    {
        $customer = Customer::factory()->create();
        $this->shopSays(false);

        $this->post(route('health.recheck', $customer))->assertSessionHas('warning');

        $this->assertFalse($customer->fresh()->latestHealthCheck->reachable);
    }

    // ---- What I changed ---------------------------------------------------

    public function test_the_log_needs_signing_in(): void
    {
        auth()->logout();

        $this->get(route('actions.index'))->assertRedirect(route('login'));
    }

    public function test_the_log_says_so_when_nothing_has_happened(): void
    {
        $this->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Nothing recorded yet');
    }

    public function test_the_log_shows_what_was_done_and_who_did_it(): void
    {
        $customer = Customer::factory()->create(['name' => 'Hawler Computer']);

        Action::record('storage_limit.changed', $customer, ['from' => 1024, 'to' => 2048]);

        $this->get(route('actions.index'))
            ->assertOk()
            ->assertSee('storage_limit.changed')
            ->assertSee('Hawler Computer')
            ->assertSee('Soran')
            ->assertSee('2048');
    }

    /** Signing in and adding an operator belong to no customer. */
    public function test_the_panels_own_doings_are_shown_as_its_own(): void
    {
        Action::record('operator.added', null, ['email' => 'karwan@soranstore.com']);

        $this->get(route('actions.index'))
            ->assertOk()
            ->assertSee('the panel itself');
    }

    public function test_the_log_can_be_narrowed_to_one_shop(): void
    {
        $mine = Customer::factory()->create(['name' => 'Mine Shop']);
        $theirs = Customer::factory()->create(['name' => 'Theirs Shop']);

        Action::record('licence.delivered', $mine, []);
        Action::record('licence.delivered', $theirs, []);

        $rows = $this->rows($this->get(route('actions.index', ['customer' => $mine->id]))->assertOk()->getContent());

        $this->assertStringContainsString('Mine Shop', $rows);
        $this->assertStringNotContainsString('Theirs Shop', $rows);
    }

    public function test_the_log_can_be_narrowed_to_one_kind_of_thing(): void
    {
        $customer = Customer::factory()->create();

        Action::record('licence.delivered', $customer, []);
        Action::record('shop.suspended', $customer, []);

        $rows = $this->rows($this->get(route('actions.index', ['action' => 'shop.suspended']))->assertOk()->getContent());

        $this->assertStringContainsString('shop.suspended', $rows);
        $this->assertStringNotContainsString('licence.delivered', $rows);
    }

    /** The record outlives the account that made it. */
    public function test_the_log_survives_the_operator_being_removed(): void
    {
        $other = User::factory()->create(['name' => 'Karwan']);
        $customer = Customer::factory()->create();

        $this->actingAs($other);
        Action::record('licence.delivered', $customer, []);

        $other->forceDelete();

        $this->actingAs($this->me)
            ->get(route('actions.index'))
            ->assertOk()
            ->assertSee('licence.delivered')
            ->assertSee('an account since removed');
    }

    /** Newest first: the question is always "what just happened". */
    public function test_the_newest_thing_is_at_the_top(): void
    {
        $customer = Customer::factory()->create();

        Action::create(['customer_id' => $customer->id, 'action' => 'older.thing', 'created_at' => now()->subDay()]);
        Action::create(['customer_id' => $customer->id, 'action' => 'newer.thing', 'created_at' => now()]);

        $body = $this->get(route('actions.index'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'older.thing'),
            strpos($body, 'newer.thing'),
            'the log is not newest first',
        );
    }

    /** Nothing anywhere may change or remove a row. */
    public function test_the_log_offers_no_way_to_change_anything(): void
    {
        $customer = Customer::factory()->create();
        Action::record('licence.delivered', $customer, []);

        $body = $this->get(route('actions.index'))->assertOk()->getContent();

        preg_match('/<main.*?>(.*)<\/main>/s', $body, $main);
        $page = $main[1] ?? '';

        // The only form on the page itself is the GET filter. The shell's
        // sign-out form is the layout's, and is on every screen.
        $this->assertSame(1, substr_count($page, '<form'), 'the log has a form that writes something');
        $this->assertStringContainsString('method="GET"', $page);
        $this->assertStringNotContainsString('_method', $page, 'the log offers a PUT or DELETE');
    }

    // ---- The shell --------------------------------------------------------

    /** Every page Section 9 names now exists, so nothing in the sidebar is dead. */
    public function test_every_page_in_the_sidebar_can_be_opened(): void
    {
        Customer::factory()->create();

        foreach (Navigation::items() as $item) {
            $this->assertNotNull($item['route'], "[{$item['label']}] is still not built");

            $this->get(route($item['route']))
                ->assertOk("[{$item['label']}] did not open");
        }
    }

    public function test_the_customer_page_links_to_their_payments(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->create();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee(route('subscriptions.show', $customer), false);
    }
}
