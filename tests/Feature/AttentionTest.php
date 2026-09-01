<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HealthCheck;
use App\Models\Licence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Only what needs Soran this week" — PANEL_DOC Section 9's three questions.
 *
 * Each is asked of the newest reading only, and each is silent about a shop it
 * could not read. The most useful thing here is what is deliberately NOT on
 * these lists: a shop that has since been tidied up, and a shop nobody could
 * reach. A warning that stays after the problem is gone stops being read.
 */
class AttentionTest extends TestCase
{
    use RefreshDatabase;

    private function shopUsing(Customer $customer, int $megabytes, int $limit = 1024, array $extra = []): HealthCheck
    {
        return HealthCheck::factory()->for($customer)->create([
            'checked_at' => now(),
            'database_bytes' => $megabytes * 1024 * 1024,
            'backups_bytes' => 0,
            'uploads_bytes' => 0,
            'storage_limit_mb' => $limit,
            ...$extra,
        ]);
    }

    // ---- Storage ----------------------------------------------------------

    public function test_a_shop_near_its_limit_is_found(): void
    {
        $full = Customer::factory()->create();
        $roomy = Customer::factory()->create();

        $this->shopUsing($full, 900);      // 88%
        $this->shopUsing($roomy, 500);     // 49%

        $found = Customer::storageOver()->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($full));
    }

    public function test_the_line_is_where_the_config_puts_it(): void
    {
        $customer = Customer::factory()->create();
        $this->shopUsing($customer, 850);  // 83%

        $this->assertSame(1, Customer::storageOver(80)->count());
        $this->assertSame(0, Customer::storageOver(90)->count());
    }

    /** Storage is all three added up, not the database alone. */
    public function test_backups_and_uploads_count_towards_the_limit(): void
    {
        $customer = Customer::factory()->create();

        HealthCheck::factory()->for($customer)->create([
            'checked_at' => now(),
            'database_bytes' => 300 * 1024 * 1024,
            'backups_bytes' => 500 * 1024 * 1024,
            'uploads_bytes' => 100 * 1024 * 1024,
            'storage_limit_mb' => 1024,
        ]);

        $this->assertSame(1, Customer::storageOver()->count());
    }

    /**
     * The newest reading, and only the newest. A shop whose backups have since
     * been pruned is not still on the list.
     */
    public function test_a_shop_that_has_since_been_tidied_up_drops_off(): void
    {
        $customer = Customer::factory()->create();

        $this->shopUsing($customer, 1000, extra: ['checked_at' => now()->subHours(2)]);
        $this->shopUsing($customer, 200, extra: ['checked_at' => now()]);

        $this->assertSame(0, Customer::storageOver()->count());
    }

    /** We do not know how full an unreachable shop is, so it is not claimed to be full. */
    public function test_a_shop_that_could_not_be_read_is_not_guessed_at(): void
    {
        $customer = Customer::factory()->create();
        HealthCheck::factory()->for($customer)->unreachable()->create(['checked_at' => now()]);

        $this->assertSame(0, Customer::storageOver()->count());
    }

    public function test_a_shop_with_no_limit_is_never_near_it(): void
    {
        $customer = Customer::factory()->create();
        $this->shopUsing($customer, 9000, extra: ['storage_limit_mb' => null]);

        $this->assertSame(0, Customer::storageOver()->count());
    }

    // ---- Nobody has used it -----------------------------------------------

    public function test_a_shop_nobody_has_opened_is_found(): void
    {
        $quiet = Customer::factory()->create();
        $busy = Customer::factory()->create();

        HealthCheck::factory()->for($quiet)->create(['checked_at' => now(), 'last_activity_at' => now()->subMonth()]);
        HealthCheck::factory()->for($busy)->create(['checked_at' => now(), 'last_activity_at' => now()->subHours(3)]);

        $found = Customer::unusedFor()->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($quiet));
    }

    /**
     * A shop provisioned this morning has no activity yet, and that is not a
     * problem. The same shop a month later is one — somebody paid and never
     * started.
     */
    public function test_a_brand_new_shop_is_not_chased_for_being_new(): void
    {
        $today = Customer::factory()->create(['started_on' => now()]);
        $abandoned = Customer::factory()->create(['started_on' => now()->subMonths(2)]);

        foreach ([$today, $abandoned] as $customer) {
            HealthCheck::factory()->for($customer)->create(['checked_at' => now(), 'last_activity_at' => null]);
        }

        $found = Customer::unusedFor()->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($abandoned));
    }

    public function test_an_unreachable_shop_is_not_called_unused(): void
    {
        $customer = Customer::factory()->create(['started_on' => now()->subYear()]);
        HealthCheck::factory()->for($customer)->unreachable()->create(['checked_at' => now()]);

        $this->assertSame(0, Customer::unusedFor()->count());
    }

    // ---- Needs chasing ----------------------------------------------------

    /**
     * The filter and the Overview must never disagree. Two answers to "who
     * needs me" is one too many.
     */
    public function test_needs_chasing_is_exactly_the_union_of_the_three_lists(): void
    {
        $expiring = Customer::factory()->create(['host' => 'a.soranstore.com']);
        Licence::factory()->for($expiring)->expiringIn(5)->create();

        $full = Customer::factory()->create(['host' => 'b.soranstore.com']);
        $this->shopUsing($full, 950);

        $quiet = Customer::factory()->create(['host' => 'c.soranstore.com']);
        HealthCheck::factory()->for($quiet)->create(['checked_at' => now(), 'last_activity_at' => now()->subMonth()]);

        $fine = Customer::factory()->create(['host' => 'd.soranstore.com']);
        Licence::factory()->for($fine)->expiringIn(200)->create();
        HealthCheck::factory()->for($fine)->create(['checked_at' => now(), 'last_activity_at' => now()->subHour()]);

        $chasing = Customer::needsChasing()->pluck('host')->sort()->values()->all();

        $this->assertSame(['a.soranstore.com', 'b.soranstore.com', 'c.soranstore.com'], $chasing);

        // And the sum of the three lists is the same set, counted once each.
        $union = collect()
            ->merge(Customer::licenceExpiringWithin(30)->pluck('id'))
            ->merge(Customer::storageOver()->pluck('id'))
            ->merge(Customer::unusedFor()->pluck('id'))
            ->unique()->sort()->values();

        $this->assertSame($union->all(), Customer::needsChasing()->pluck('id')->sort()->values()->all());
    }

    /** A shop on two lists at once is one shop, not two. */
    public function test_a_shop_with_two_problems_is_listed_once(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->expiringIn(2)->create();
        $this->shopUsing($customer, 1000);

        $this->assertSame(1, Customer::needsChasing()->count());
    }

    public function test_suspended_and_ended_shops_are_never_chased(): void
    {
        foreach ([Customer::SUSPENDED, Customer::ENDED] as $status) {
            $customer = Customer::factory()->create(['status' => $status, 'started_on' => now()->subYear()]);
            Licence::factory()->for($customer)->expiringIn(1)->create();
            $this->shopUsing($customer, 1020);
            HealthCheck::factory()->for($customer)->create(['checked_at' => now(), 'last_activity_at' => null]);
        }

        $this->assertSame(0, Customer::needsChasing()->count());
        $this->assertSame(0, Customer::storageOver()->count());
        $this->assertSame(0, Customer::unusedFor()->count());
    }
}
