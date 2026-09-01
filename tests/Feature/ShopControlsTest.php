<?php

namespace Tests\Feature;

use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Models\Licence;
use App\Models\User;
use App\Support\ShopReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Storage limits, suspending and resuming — PANEL_DOC Section 7.
 *
 * A real folder with a real `.env`, written by the real writer. Only the shop's
 * ANSWER is faked, for the same reason as in RenewTest: running the shop system
 * is its own suite's job.
 *
 * Suspending deliberately uses the licence as the lever, so the shop lands in
 * the state the shop system already has a considered answer for — read-only,
 * with reading, printing and signing in untouched. PROJECT_DOC is explicit that
 * a shop locked out of its own records is a shop that will never pay another
 * invoice, and being paid is the point of suspending somebody.
 */
class ShopControlsTest extends TestCase
{
    use RefreshDatabase;

    private string $home;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->home = sys_get_temp_dir().'/shop-control-'.bin2hex(random_bytes(6));
        mkdir($this->home, 0755, true);
        file_put_contents($this->home.'/.env', implode("\n", [
            'APP_NAME="Hawler Computer"',
            'DB_DATABASE=hawler_shop',
            'LICENCE_KEY=eyJvbGQ.signature',
            'STORAGE_LIMIT_MB=1024',
        ])."\n");

        $this->customer = Customer::factory()->create([
            'name' => 'Hawler Computer',
            'host' => 'hawler.soranstore.com',
            'shop_home' => $this->home,
            'status' => Customer::ACTIVE,
            'storage_limit_mb' => 1024,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->home);

        parent::tearDown();
    }

    private function shopSays(?string $state): void
    {
        $this->swap(ShopReader::class, new class($state) implements ShopReader
        {
            public function __construct(private ?string $state) {}

            public function read(Customer $customer): ShopReading
            {
                return new ShopReading(reachable: true, licenceState: $this->state);
            }

            public function licenceState(Customer $customer): ?string
            {
                return $this->state;
            }
        });
    }

    private function env(): string
    {
        return (string) file_get_contents($this->home.'/.env');
    }

    // ---- Storage --------------------------------------------------------

    public function test_a_new_limit_is_written_where_the_shop_reads_it(): void
    {
        $this->post(route('customers.storage', $this->customer), ['storage_limit_mb' => 4096])
            ->assertSessionHas('success');

        $this->assertStringContainsString('STORAGE_LIMIT_MB=4096', $this->env());
        $this->assertSame(4096, $this->customer->fresh()->storage_limit_mb);
    }

    /** Section 7: logged, from → to. */
    public function test_changing_the_limit_is_logged_both_ways(): void
    {
        $this->post(route('customers.storage', $this->customer), ['storage_limit_mb' => 4096]);

        $logged = Action::where('action', 'storage_limit.changed')->first();

        $this->assertSame(1024, $logged->detail['from']);
        $this->assertSame(4096, $logged->detail['to']);
        $this->assertSame(auth()->id(), $logged->user_id);
        $this->assertSame($this->customer->id, $logged->customer_id);
    }

    public function test_an_empty_limit_means_no_ceiling_at_all(): void
    {
        $this->post(route('customers.storage', $this->customer), ['storage_limit_mb' => null])
            ->assertSessionHas('success');

        $this->assertNull($this->customer->fresh()->storage_limit_mb);
        $this->assertStringContainsString('STORAGE_LIMIT_MB=', $this->env());
    }

    public function test_a_silly_limit_is_refused(): void
    {
        $this->post(route('customers.storage', $this->customer), ['storage_limit_mb' => 4])
            ->assertSessionHasErrors('storage_limit_mb');

        $this->assertSame(1024, $this->customer->fresh()->storage_limit_mb);
    }

    /** Setting it to what it already is is not a change, and not a log entry. */
    public function test_setting_the_same_limit_changes_and_logs_nothing(): void
    {
        $this->post(route('customers.storage', $this->customer), ['storage_limit_mb' => 1024]);

        $this->assertSame(0, Action::where('action', 'storage_limit.changed')->count());
        $this->assertFileDoesNotExist($this->home.'/.env.bak');
    }

    /** The panel's copy must never claim something the shop was not told. */
    public function test_a_shop_that_cannot_be_written_leaves_the_panels_record_alone(): void
    {
        unlink($this->home.'/.env');

        $this->post(route('customers.storage', $this->customer), ['storage_limit_mb' => 4096])
            ->assertSessionHas('warning');

        $this->assertSame(1024, $this->customer->fresh()->storage_limit_mb);
    }

    // ---- Suspend --------------------------------------------------------

    public function test_suspending_takes_the_licence_out_of_the_shop(): void
    {
        $this->shopSays('missing');

        $this->post(route('customers.suspend', $this->customer), ['why' => 'Two months behind'])
            ->assertSessionHas('warning');

        $this->assertStringContainsString('LICENCE_KEY=', $this->env());
        $this->assertStringNotContainsString('eyJvbGQ.signature', $this->env());
        $this->assertSame(Customer::SUSPENDED, $this->customer->fresh()->status);
    }

    public function test_suspending_is_logged_with_the_reason(): void
    {
        $this->shopSays('missing');

        $this->post(route('customers.suspend', $this->customer), ['why' => 'Two months behind']);

        $logged = Action::where('action', 'shop.suspended')->first();

        $this->assertSame('Two months behind', $logged->detail['why']);
        $this->assertSame('missing', $logged->detail['shop_says']);
        $this->assertSame(auth()->id(), $logged->user_id);
    }

    /**
     * The failure that matters when suspending: the file is written and the
     * shop does not notice, so the customer carries on trading and nobody
     * finds out until the next hourly check.
     */
    public function test_a_shop_that_did_not_notice_being_suspended_is_said_out_loud(): void
    {
        $this->shopSays('valid');

        $this->post(route('customers.suspend', $this->customer));

        $this->assertStringContainsString('may still be trading', session('warning'));
    }

    /** A suspended shop drops off everything the Overview chases. */
    public function test_a_suspended_shop_is_no_longer_chased(): void
    {
        $this->shopSays('missing');
        Licence::factory()->for($this->customer)->expiringIn(2)->create();

        $this->assertSame(1, Customer::needsChasing()->count());

        $this->post(route('customers.suspend', $this->customer));

        $this->assertSame(0, Customer::needsChasing()->count());
        $this->assertSame(0, Customer::live()->count());
    }

    /**
     * A suspended shop reporting `missing` is the panel's own doing. Flagging
     * it as a disagreement would be the panel arguing with itself.
     */
    public function test_a_suspended_shop_is_not_reported_as_disagreeing_with_the_panel(): void
    {
        $this->customer->update(['status' => Customer::SUSPENDED]);
        Licence::factory()->for($this->customer)->create();
        HealthCheck::factory()->for($this->customer)->create([
            'checked_at' => now(), 'licence_state' => 'missing',
        ]);

        $this->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertDontSee('does not match what the panel believes');
    }

    // ---- Resume ---------------------------------------------------------

    public function test_resuming_puts_back_the_licence_it_already_had(): void
    {
        $this->shopSays('valid');

        $licence = Licence::factory()->for($this->customer)->create([
            'licence_key' => 'eyJyZWFs.thesignature',
            'expires_on' => now()->addMonths(6),
        ]);
        $this->customer->update(['status' => Customer::SUSPENDED]);

        $this->post(route('customers.resume', $this->customer))->assertSessionHas('success');

        $this->assertStringContainsString('LICENCE_KEY=eyJyZWFs.thesignature', $this->env());
        $this->assertSame(Customer::ACTIVE, $this->customer->fresh()->status);
        $this->assertSame(1, Licence::count(), 'resuming must not issue a new licence');
    }

    public function test_resuming_is_logged(): void
    {
        $this->shopSays('valid');
        Licence::factory()->for($this->customer)->create(['expires_on' => now()->addMonths(6)]);
        $this->customer->update(['status' => Customer::SUSPENDED]);

        $this->post(route('customers.resume', $this->customer));

        $this->assertNotNull(Action::where('action', 'shop.resumed')->first());
    }

    /** Putting back a licence that has since run out leaves them read-only anyway. */
    public function test_resuming_on_a_licence_that_has_run_out_is_refused_with_a_reason(): void
    {
        $this->shopSays('valid');
        Licence::factory()->for($this->customer)->create(['expires_on' => now()->subWeek()]);
        $this->customer->update(['status' => Customer::SUSPENDED]);

        $this->post(route('customers.resume', $this->customer))->assertSessionHas('warning');

        $this->assertSame(Customer::SUSPENDED, $this->customer->fresh()->status);
        $this->assertStringContainsString('Renew it instead', session('warning'));
    }

    public function test_a_shop_with_no_licence_on_record_cannot_be_resumed(): void
    {
        $this->shopSays('missing');
        $this->customer->update(['status' => Customer::SUSPENDED]);

        $this->post(route('customers.resume', $this->customer))->assertSessionHas('warning');

        $this->assertStringContainsString('no licence on record', session('warning'));
        $this->assertSame(Customer::SUSPENDED, $this->customer->fresh()->status);
    }

    /**
     * A resume the shop did not accept must not read as good news.
     *
     * The first version reported it green with the words "still read-only"
     * inside it, which is the worst of both: the colour says done and the text
     * says not.
     */
    public function test_a_shop_that_did_not_come_back_is_said_out_loud(): void
    {
        $this->shopSays('missing');
        Licence::factory()->for($this->customer)->create(['expires_on' => now()->addMonths(6)]);
        $this->customer->update(['status' => Customer::SUSPENDED]);

        $this->post(route('customers.resume', $this->customer))->assertSessionMissing('success');

        $this->assertStringContainsString('still read-only', session('warning'));
    }

    // ---- The screen -----------------------------------------------------

    public function test_the_danger_zone_offers_suspend_with_the_host_typed(): void
    {
        $this->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertSee('Suspend this shop')
            ->assertSee('Type hawler.soranstore.com to suspend')
            ->assertSee('Change the storage limit');
    }

    public function test_a_suspended_shop_is_offered_the_way_back(): void
    {
        $this->customer->update(['status' => Customer::SUSPENDED]);

        $this->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertSee('Let this shop trade again')
            ->assertDontSee('Suspend this shop');
    }

    public function test_the_controls_need_signing_in(): void
    {
        auth()->logout();

        $this->post(route('customers.suspend', $this->customer))->assertRedirect(route('login'));
        $this->post(route('customers.storage', $this->customer))->assertRedirect(route('login'));
    }
}
