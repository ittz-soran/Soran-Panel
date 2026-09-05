<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HealthCheck;
use App\Models\Licence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three screens that only read — PANEL_DOC Section 9, build order step 5.
 *
 * What is held here is mostly about what these screens must NOT say. A number
 * that is unknown must not be drawn as zero, a shop that has been tidied up
 * must drop off the list, and the Overview's count and the Customers filter
 * must never disagree — because a screen that overstates once stops being read.
 */
class ScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function healthy(Customer $customer, array $extra = []): HealthCheck
    {
        return HealthCheck::factory()->for($customer)->create([
            'checked_at' => now(),
            'last_activity_at' => now()->subHour(),
            ...$extra,
        ]);
    }

    // ---- Getting in -------------------------------------------------------

    public function test_the_screens_need_signing_in(): void
    {
        auth()->logout();

        $customer = Customer::factory()->create();

        $this->get(route('overview'))->assertRedirect(route('login'));
        $this->get(route('customers.index'))->assertRedirect(route('login'));
        $this->get(route('customers.show', $customer))->assertRedirect(route('login'));
    }

    // ---- Overview ---------------------------------------------------------

    public function test_the_overview_says_so_when_there_are_no_customers(): void
    {
        $this->get(route('overview'))
            ->assertOk()
            ->assertSee('No customers yet')
            ->assertDontSee('Nothing needs you this week');
    }

    /**
     * "Nothing needs you" and "there is nothing here" are different statements,
     * and only one of them is reassuring.
     */
    public function test_the_overview_distinguishes_all_well_from_nothing_recorded(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->expiringIn(200)->create();
        $this->healthy($customer, ['database_bytes' => 1024, 'backups_bytes' => 0, 'uploads_bytes' => 0]);

        $this->get(route('overview'))
            ->assertOk()
            ->assertSee('Nothing needs you this week')
            ->assertDontSee('No customers yet');
    }

    public function test_the_overview_shows_the_three_lists_section_9_names(): void
    {
        $expiring = Customer::factory()->create(['name' => 'Expiring Shop']);
        Licence::factory()->for($expiring)->expiringIn(4)->create();
        $this->healthy($expiring);

        $full = Customer::factory()->create(['name' => 'Full Shop']);
        $this->healthy($full, [
            'database_bytes' => 950 * 1024 * 1024,
            'backups_bytes' => 0, 'uploads_bytes' => 0, 'storage_limit_mb' => 1024,
        ]);

        $quiet = Customer::factory()->create(['name' => 'Quiet Shop']);
        $this->healthy($quiet, ['last_activity_at' => now()->subMonth()]);

        $this->get(route('overview'))
            ->assertOk()
            ->assertSee('Licences running out')
            ->assertSee('Expiring Shop')
            ->assertSee('Storage near its limit')
            ->assertSee('Full Shop')
            ->assertSee('Nobody has used them')
            ->assertSee('Quiet Shop');
    }

    /** A list with nobody on it is not drawn at all. */
    public function test_an_empty_list_is_left_off_rather_than_shown_empty(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->expiringIn(3)->create();
        $this->healthy($customer);

        $this->get(route('overview'))
            ->assertOk()
            ->assertSee('Licences running out')
            ->assertDontSee('Storage near its limit')
            ->assertDontSee('Nobody has used them');
    }

    public function test_the_overview_warns_when_a_shop_has_never_been_checked(): void
    {
        Customer::factory()->create();

        $this->get(route('overview'))
            ->assertOk()
            ->assertSee('never been checked');
    }

    public function test_the_month_is_worth_what_the_live_shops_pay(): void
    {
        Customer::factory()->create(['monthly_fee' => 50000]);
        Customer::factory()->trial()->create(['monthly_fee' => 25000]);
        Customer::factory()->ended()->create(['monthly_fee' => 90000]);

        $this->get(route('overview'))
            ->assertOk()
            ->assertSee('75,000 IQD')
            ->assertDontSee('165,000');
    }

    // ---- Customers --------------------------------------------------------

    public function test_the_customer_list_shows_what_section_9_asks_for(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Bazaar Computer',
            'host' => 'bazaar.soranstore.com',
            'monthly_fee' => 50000,
        ]);
        Licence::factory()->for($customer)->expiringIn(90)->create();
        $this->healthy($customer, [
            'database_bytes' => 40 * 1024 * 1024,
            'backups_bytes' => 0, 'uploads_bytes' => 0,
            'migrations_run' => 32, 'migrations_total' => 32,
        ]);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Bazaar Computer')
            ->assertSee('bazaar.soranstore.com')
            ->assertSee('40 MB')
            ->assertSee('Up to date')
            ->assertSee('50,000')
            ->assertSee('Last used');
    }

    /**
     * The filter and the Overview must never disagree — two answers to "who
     * needs me" is one too many.
     */
    public function test_needs_chasing_shows_exactly_the_shops_the_overview_lists(): void
    {
        $chasing = Customer::factory()->create(['name' => 'Chase Me']);
        Licence::factory()->for($chasing)->expiringIn(3)->create();
        $this->healthy($chasing);

        $fine = Customer::factory()->create(['name' => 'Perfectly Fine']);
        Licence::factory()->for($fine)->expiringIn(300)->create();
        $this->healthy($fine);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Chase Me')
            ->assertSee('Perfectly Fine');

        $this->get(route('customers.index', ['show' => 'chasing']))
            ->assertOk()
            ->assertSee('Chase Me')
            ->assertDontSee('Perfectly Fine');
    }

    public function test_the_filter_says_so_when_nothing_needs_chasing(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->expiringIn(300)->create();
        $this->healthy($customer);

        $this->get(route('customers.index', ['show' => 'chasing']))
            ->assertOk()
            ->assertSee('Nothing needs chasing');
    }

    /** A shop that could not be read says so rather than showing zeroes. */
    public function test_an_unreadable_shop_is_unknown_rather_than_empty(): void
    {
        $customer = Customer::factory()->create();
        HealthCheck::factory()->for($customer)->unreachable()->create(['checked_at' => now()]);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('unreachable')
            ->assertSee('unknown');
    }

    /** The list must not run two extra queries per row. */
    public function test_the_customer_list_does_not_grow_a_query_per_shop(): void
    {
        foreach (range(1, 5) as $i) {
            $customer = Customer::factory()->create();
            Licence::factory()->for($customer)->create();
            $this->healthy($customer);
        }

        \DB::enableQueryLog();
        $this->get(route('customers.index'))->assertOk();
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "the list ran {$queries} queries for five shops");
    }

    // ---- One customer -----------------------------------------------------

    public function test_one_customer_shows_the_licence_and_its_whole_history(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bazaar Computer']);

        Licence::factory()->for($customer)->create([
            'licence_id' => 'OLD1-AAAA',
            'issued_on' => now()->subMonths(2), 'expires_on' => now()->subMonth(),
        ]);
        Licence::factory()->for($customer)->revoked('replaced')->create(['licence_id' => 'GONE-BBBB']);
        Licence::factory()->for($customer)->undelivered()->create(['licence_id' => 'NEVR-CCCC']);
        $current = Licence::factory()->for($customer)->create([
            'licence_id' => 'NOW1-DDDD',
            'issued_on' => now()->subDays(3), 'expires_on' => now()->addMonths(6),
        ]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('NOW1-DDDD')
            ->assertSee('OLD1-AAAA')
            ->assertSee('GONE-BBBB')
            ->assertSee('NEVR-CCCC')
            ->assertSee('running now')
            ->assertSee('revoked')
            ->assertSee('never delivered')
            ->assertSee('Every licence ever issued (4)');
    }

    public function test_one_customer_breaks_storage_into_its_three_parts(): void
    {
        $customer = Customer::factory()->create();
        $this->healthy($customer, [
            'database_bytes' => 100 * 1024 * 1024,
            'backups_bytes' => 300 * 1024 * 1024,
            'uploads_bytes' => 24 * 1024 * 1024,
            'storage_limit_mb' => 1024,
        ]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Database')
            ->assertSee('100 MB')
            ->assertSee('Backups')
            ->assertSee('300 MB')
            ->assertSee('Uploads')
            ->assertSee('24 MB');
    }

    public function test_one_customer_says_whether_they_are_using_it(): void
    {
        $customer = Customer::factory()->create();
        $this->healthy($customer, ['users_count' => 3, 'products_count' => 412, 'sales_count' => 1875]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Are they using it?')
            ->assertSee('412')
            ->assertSee('1,875');
    }

    /**
     * The cross-check Section 8 exists to make: the shop's own opinion beside
     * the panel's, and a disagreement said out loud.
     */
    public function test_one_customer_surfaces_the_shop_disagreeing_with_the_panel(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->expiringIn(60)->create();
        $this->healthy($customer, ['licence_state' => 'wrong_host']);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('The shop itself reports')
            ->assertSee('wrong_host')
            ->assertSee('does not match what the panel believes');
    }

    public function test_one_customer_stays_quiet_when_the_shop_agrees(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->expiringIn(60)->create();
        $this->healthy($customer, ['licence_state' => 'valid']);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('The shop itself reports')
            ->assertDontSee('does not match what the panel believes');
    }

    /**
     * Section 7's guard rail: the reason on the disabled button, not discovered
     * after pressing it. Nothing here may write yet.
     */
    public function test_the_danger_zone_names_what_is_coming_and_disables_it(): void
    {
        $customer = Customer::factory()->create();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Danger zone')
            ->assertSee('Renew the licence')
            ->assertSee('Renew…', false)
            ->assertSee('Suspend this shop')
            ->assertSee('Run this shop’s migrations', false)
            ->assertSee('Not built yet')
            ->assertSee('may never write to this shop’s business tables', false);
    }

    public function test_a_shop_that_could_not_be_read_says_so_at_the_top(): void
    {
        $customer = Customer::factory()->create();
        HealthCheck::factory()->for($customer)->unreachable('Connection refused')->create(['checked_at' => now()]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('could not read this shop')
            ->assertSee('Connection refused');
    }

    public function test_a_customer_with_no_licence_says_the_shop_is_read_only(): void
    {
        $customer = Customer::factory()->create();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('No licence')
            ->assertSee('No licence has been delivered to this shop.');
    }

    /**
     * A soft-deleted customer is a REMOVED shop, and its page stays.
     *
     * This test used to assert the opposite — that the page 404s — and it was
     * right while nothing could remove a shop, because a hidden customer then
     * meant somebody had tidied a live one out of the list. `ShopRemover` gave
     * the flag a meaning: the folders, the subdomain and the database are gone.
     *
     * What is left is the record, and Section 5 is explicit that the licence
     * history and the payments outlive the customer. A 404 would make every
     * penny a closed shop ever paid unreachable, so the page is readable and it
     * is the CONTROLS that are gone — checked below, and by RemoveShopTest.
     */
    public function test_a_removed_customers_page_is_still_readable_but_does_nothing(): void
    {
        $customer = Customer::factory()->create();
        $customer->delete();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('This shop was removed')
            ->assertDontSee('Danger zone');

        // Every route that would change a shop refuses to find a removed one.
        $this->post(route('customers.suspend', $customer))->assertNotFound();
        $this->post(route('customers.resume', $customer))->assertNotFound();
        $this->get(route('customers.renew', $customer))->assertNotFound();
        $this->delete(route('customers.remove', $customer))->assertNotFound();
    }

    /**
     * The Overview's count and the Customers filter must be the same number.
     *
     * A shop can be on two lists at once — a licence running out on a shop
     * nobody has opened is the ordinary case — and the first version added the
     * three list lengths, so the Overview said three where the filter showed
     * two. The scopes agreed with each other the whole time, which is why only
     * opening the page caught it.
     */
    public function test_a_shop_on_two_lists_is_counted_once_on_the_overview(): void
    {
        $both = Customer::factory()->create(['name' => 'Two Problems', 'started_on' => now()->subYear()]);
        Licence::factory()->for($both)->expiringIn(3)->create();
        $this->healthy($both, ['last_activity_at' => now()->subMonths(2)]);

        $one = Customer::factory()->create(['name' => 'One Problem']);
        Licence::factory()->for($one)->expiringIn(2)->create();
        $this->healthy($one);

        // Two shops need Soran, across three list entries.
        $this->assertSame(2, Customer::needsChasing()->count());

        $overview = $this->get(route('overview'))->assertOk();

        // The tile says how many shops, not how many list rows.
        $this->assertMatchesRegularExpression(
            '/2\s*<\/div>\s*<div class="text-secondary small">shops on the lists above/',
            $overview->getContent(),
            'the Overview counted a shop once per list it appears on',
        );

        $this->get(route('customers.index', ['show' => 'chasing']))
            ->assertOk()
            ->assertSee('Two Problems')
            ->assertSee('One Problem');
    }

    /**
     * A shop that has just gone down keeps its last real figures.
     *
     * Section 5 keeps snapshots in a table rather than columns on the customer
     * precisely so "a failed check must not wipe the last good reading". The
     * first version of these screens read the newest check for everything, so
     * the moment a shop went down every column said "unknown" — throwing away
     * the reading, and the reason the table exists. It is when a shop has just
     * gone down that somebody most wants to know what it looked like an hour
     * ago.
     */
    public function test_a_shop_that_went_down_still_shows_its_last_real_figures(): void
    {
        $customer = Customer::factory()->create(['name' => 'Ranya Mobile']);

        $this->healthy($customer, [
            'checked_at' => now()->subHours(2),
            'database_bytes' => 9 * 1024 * 1024,
            'backups_bytes' => 4 * 1024 * 1024,
            'uploads_bytes' => 0,
            'products_count' => 412,
        ]);

        HealthCheck::factory()->for($customer)->unreachable('Connection refused')
            ->create(['checked_at' => now()]);

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('unreachable')       // what it is doing now
            ->assertSee('13 MB');            // what it was, two hours ago

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('could not read this shop')
            ->assertSee('Connection refused')
            ->assertSee('the last')
            ->assertSee('412')
            ->assertSee('13 MB');
    }

    /** A shop never read at all has nothing to fall back on, and says so. */
    public function test_a_shop_never_read_says_it_has_no_figures_at_all(): void
    {
        $customer = Customer::factory()->create();
        HealthCheck::factory()->for($customer)->unreachable()->create(['checked_at' => now()]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('never been read')
            ->assertDontSee('the last reading that worked');
    }

    /**
     * A reading taken before the licence was delivered cannot disagree with it.
     *
     * The hourly check runs on its own schedule, so straight after a renewal
     * the newest reading is usually older than the licence. Comparing them then
     * reported "the shop says unlicensed" about a shop that had just been
     * asked, answered `valid`, and been fine ever since. A false alarm here is
     * worse than none: the point of the line is that a real disagreement gets
     * noticed.
     */
    public function test_a_reading_older_than_the_licence_is_not_called_a_disagreement(): void
    {
        $customer = Customer::factory()->create();

        Licence::factory()->for($customer)->create([
            'issued_on' => now()->subHour(),
            'expires_on' => now()->addMonths(6),
            'delivered_at' => now()->subMinutes(10),
        ]);

        // Taken before the licence went on.
        $this->healthy($customer, ['checked_at' => now()->subHours(3), 'licence_state' => 'unlicensed']);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee('does not match what the panel believes');
    }

    /** A reading taken since the licence went on still speaks up. */
    public function test_a_reading_since_the_licence_still_reports_a_disagreement(): void
    {
        $customer = Customer::factory()->create();

        Licence::factory()->for($customer)->create([
            'issued_on' => now()->subDay(),
            'expires_on' => now()->addMonths(6),
            'delivered_at' => now()->subHours(4),
        ]);

        $this->healthy($customer, ['checked_at' => now(), 'licence_state' => 'wrong_host']);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('does not match what the panel believes');
    }
}
