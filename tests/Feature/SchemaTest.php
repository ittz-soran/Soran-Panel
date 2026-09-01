<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Models\Licence;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The six tables of PANEL_DOC Section 5, and the things Section 5 says about
 * them that a column list alone does not carry.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_six_tables_exist(): void
    {
        foreach (['users', 'customers', 'licences', 'payments', 'health_checks', 'actions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Section 5 names [{$table}] and it is not there.");
        }
    }

    public function test_customers_carries_what_section_5_lists(): void
    {
        $this->assertTrue(Schema::hasColumns('customers', [
            'name', 'contact_name', 'phone', 'email', 'host',
            'shop_home', 'public_path', 'database_name', 'database_user',
            'status', 'monthly_fee', 'storage_limit_mb', 'language',
            'started_on', 'notes', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    /** A licence binds to one host, so two customers cannot share one. */
    public function test_a_host_belongs_to_one_customer(): void
    {
        Customer::factory()->create(['host' => 'bazaar.soranstore.com']);

        $this->expectException(UniqueConstraintViolationException::class);

        Customer::factory()->create(['host' => 'bazaar.soranstore.com']);
    }

    /**
     * PROJECT_DOC Section 2: integer dinars, never decimal. There is no smaller
     * unit in circulation and a float is a rounding error in somebody's invoice.
     */
    public function test_money_is_whole_dinars(): void
    {
        $customer = Customer::factory()->create(['monthly_fee' => 50000]);
        $payment = Payment::factory()->for($customer)->create(['amount' => 150000]);

        $this->assertIsInt($customer->fresh()->monthly_fee);
        $this->assertIsInt($payment->fresh()->amount);
    }

    /**
     * Section 5 spells this column `key`. It is `licence_key` here, because KEY
     * is a reserved word in MariaDB: it survives the query builder, which quotes
     * its own identifiers, and breaks the moment anyone types SQL by hand —
     * which for a licence column is exactly what happens. Section 1 records this
     * project having already shipped one MariaDB reserved word that SQLite
     * accepted.
     */
    public function test_the_signed_string_is_not_in_a_reserved_word_column(): void
    {
        $this->assertTrue(Schema::hasColumn('licences', 'licence_key'));
        $this->assertFalse(Schema::hasColumn('licences', 'key'));
    }

    /**
     * A renewal is a new row, never an edit — and what the shop is running is
     * the newest DELIVERED, unrevoked one. Not simply the newest row: a licence
     * issued and never confirmed (Section 6 step 7) is not what the shop runs
     * on, and treating it as though it were leaves a customer who paid locked
     * out while the panel shows them as fine.
     */
    public function test_the_current_licence_is_the_newest_one_that_actually_arrived(): void
    {
        $customer = Customer::factory()->create();

        $old = Licence::factory()->for($customer)->create([
            'issued_on' => now()->subMonths(2), 'expires_on' => now()->subMonth(),
        ]);
        $current = Licence::factory()->for($customer)->create([
            'issued_on' => now()->subMonth(), 'expires_on' => now()->addMonth(),
        ]);

        // Newer than both, and never confirmed as reaching the shop.
        Licence::factory()->for($customer)->undelivered()->create(['issued_on' => now()]);

        // Newer still, and taken back.
        Licence::factory()->for($customer)->revoked()->create(['issued_on' => now()]);

        $this->assertSame($current->id, $customer->fresh()->currentLicence->id);
        $this->assertCount(4, $customer->licences, 'every licence ever issued is kept');
        $this->assertNotSame($old->id, $customer->fresh()->currentLicence->id);
    }

    /** Null expiry means sold outright, and never needs chasing. */
    public function test_a_perpetual_licence_never_expires_and_is_not_chased(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->perpetual()->create();

        $licence = $customer->fresh()->currentLicence;

        $this->assertTrue($licence->isPerpetual());
        $this->assertNull($licence->daysLeft());
        $this->assertSame(0, Licence::expiringWithin(30)->count());
    }

    public function test_licences_running_out_soon_are_found(): void
    {
        Licence::factory()->expiringIn(5)->create();
        Licence::factory()->expiringIn(90)->create();
        Licence::factory()->undelivered()->expiringIn(3)->create();

        $found = Licence::expiringWithin(14)->get();

        $this->assertCount(1, $found, 'only delivered licences, and only the near one');
        $this->assertSame(5, $found->first()->daysLeft());
    }

    /**
     * Two date pairs, not one. A customer who pays three months at once is not
     * chased next week.
     */
    public function test_a_payment_records_which_period_it_buys(): void
    {
        $customer = Customer::factory()->create();

        Payment::factory()->for($customer)->covering(3)->create([
            'paid_on' => '2026-09-01',
            'covers_from' => '2026-09-01',
            'amount' => 150000,
        ]);

        $payment = $customer->payments()->first();

        $this->assertSame('2026-11-30', $payment->covers_to->toDateString());
        $this->assertSame(3, $payment->monthsCovered());
        $this->assertSame('2026-11-30', $customer->paidUpTo()->toDateString());
    }

    public function test_paid_up_to_is_null_when_nothing_has_been_paid(): void
    {
        $this->assertNull(Customer::factory()->create()->paidUpTo());
    }

    /**
     * Snapshots in a table, not columns on `customers`: a failed check must not
     * wipe the last good reading.
     */
    public function test_a_failed_check_leaves_the_last_good_reading_standing(): void
    {
        $customer = Customer::factory()->create();

        HealthCheck::factory()->for($customer)->create([
            'checked_at' => now()->subHour(),
            'database_bytes' => 40 * 1024 * 1024,
        ]);

        HealthCheck::factory()->for($customer)->unreachable()->create(['checked_at' => now()]);

        $customer = $customer->fresh();

        $this->assertCount(2, $customer->healthChecks);
        $this->assertFalse($customer->latestHealthCheck->reachable);
        $this->assertNotEmpty($customer->latestHealthCheck->error);

        // Yesterday's numbers are still there to compare against.
        $good = $customer->healthChecks()->where('reachable', true)->first();
        $this->assertSame(40 * 1024 * 1024, $good->database_bytes);
    }

    /**
     * A check that could not look is not a shop with nothing in it. Null and
     * zero have to stay distinguishable or the Overview reports an empty shop
     * where it should report a broken check.
     */
    public function test_an_unreachable_check_knows_nothing_rather_than_zero(): void
    {
        $check = HealthCheck::factory()->unreachable()->create();

        $this->assertNull($check->totalBytes());
        $this->assertNull($check->storagePercent());
        $this->assertNull($check->migrationsPending());
        $this->assertNull($check->dataCheckPassed());
        $this->assertNull($check->products_count);
    }

    public function test_a_reachable_check_adds_its_storage_up(): void
    {
        $check = HealthCheck::factory()->create([
            'database_bytes' => 100 * 1024 * 1024,
            'backups_bytes' => 300 * 1024 * 1024,
            'uploads_bytes' => 24 * 1024 * 1024,
            'storage_limit_mb' => 1024,
        ]);

        $this->assertSame(424 * 1024 * 1024, $check->totalBytes());
        $this->assertSame(41.4, $check->storagePercent());
    }

    /** No limit is not the same as an empty shop. */
    public function test_a_shop_with_no_limit_has_no_percentage(): void
    {
        $this->assertNull(HealthCheck::factory()->create(['storage_limit_mb' => null])->storagePercent());
    }

    public function test_pending_migrations_are_counted(): void
    {
        $behind = HealthCheck::factory()->create(['migrations_run' => 25, 'migrations_total' => 29]);
        $current = HealthCheck::factory()->create(['migrations_run' => 29, 'migrations_total' => 29]);

        $this->assertSame(4, $behind->migrationsPending());
        $this->assertSame(0, $current->migrationsPending());
    }

    /** A contradiction in a shop's own data is reported, never repaired. */
    public function test_the_data_check_reports_a_contradiction(): void
    {
        $this->assertTrue(HealthCheck::factory()->create(['data_check_passed' => 17, 'data_check_total' => 17])->dataCheckPassed());
        $this->assertFalse(HealthCheck::factory()->create(['data_check_passed' => 16, 'data_check_total' => 17])->dataCheckPassed());
    }

    /** A log with an updated_at is a log somebody can edit. */
    public function test_the_action_log_is_append_only(): void
    {
        $this->assertTrue(Schema::hasColumn('actions', 'created_at'));
        $this->assertFalse(Schema::hasColumn('actions', 'updated_at'));

        $action = Action::factory()->create();
        $action->touch();

        $this->assertArrayNotHasKey('updated_at', $action->fresh()->getAttributes());
    }

    public function test_an_action_records_what_changed_and_who_did_it(): void
    {
        $user = User::factory()->create(['name' => 'Soran']);
        $customer = Customer::factory()->create();

        $action = Action::factory()->for($customer)->for($user)->create([
            'action' => 'storage_limit.changed',
            'detail' => ['from' => 1024, 'to' => 2048],
            'ip_address' => '192.168.1.10',
        ]);

        $action = $action->fresh();

        $this->assertSame('Soran', $action->user->name);
        $this->assertSame($customer->id, $action->customer->id);
        $this->assertSame(['from' => 1024, 'to' => 2048], $action->detail);
    }

    /**
     * Signing in and adding an operator belong to no customer, and the record
     * of what an operator did outlives the operator.
     */
    public function test_an_action_survives_without_a_customer_and_without_its_operator(): void
    {
        $user = User::factory()->create();
        $action = Action::factory()->withoutCustomer()->for($user)->create();

        $this->assertNull($action->customer_id);

        $user->forceDelete();

        $this->assertNotNull($action->fresh(), 'the record must outlive the account that made it');
        $this->assertNull($action->fresh()->user_id);
    }

    /** Section 5: soft deletes on customers and payments, so history survives. */
    public function test_customers_and_payments_are_only_ever_hidden(): void
    {
        $customer = Customer::factory()->create();
        $payment = Payment::factory()->for($customer)->create();

        $customer->delete();
        $payment->delete();

        $this->assertSame(0, Customer::count());
        $this->assertSame(1, Customer::withTrashed()->count());
        $this->assertSame(1, Payment::withTrashed()->count());
    }

    public function test_live_customers_are_the_ones_worth_chasing(): void
    {
        Customer::factory()->create();
        Customer::factory()->trial()->create();
        Customer::factory()->suspended()->create();
        Customer::factory()->ended()->create();

        $this->assertSame(2, Customer::live()->count());
    }

    /**
     * The Overview asks "which shops need me", and a shop that renews every
     * month must not be on that list twelve times over.
     *
     * Found by driving it rather than by the tests: a customer who had renewed
     * showed twice, because the expired licence the renewal replaced still
     * matched. On the Overview that is a false alarm about a shop that is fine.
     */
    public function test_a_shop_that_renewed_is_not_chased_for_the_licence_it_replaced(): void
    {
        $renewed = Customer::factory()->create();
        Licence::factory()->for($renewed)->create([
            'issued_on' => now()->subMonths(2), 'expires_on' => now()->subMonth(),
        ]);
        Licence::factory()->for($renewed)->create([
            'issued_on' => now()->subDays(2), 'expires_on' => now()->addMonths(6),
        ]);

        $running_out = Customer::factory()->create();
        Licence::factory()->for($running_out)->expiringIn(9)->create(['issued_on' => now()->subDays(21)]);

        $found = Customer::licenceExpiringWithin(14)->get();

        $this->assertCount(1, $found);
        $this->assertSame($running_out->id, $found->first()->id);

        // Counting licences instead of shops is what got this wrong.
        $this->assertSame(2, Licence::expiringWithin(14)->count());
    }

    /** A licence that ran out last week needs Soran more than one running out next week. */
    public function test_a_shop_already_past_its_date_is_on_the_list(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->create([
            'issued_on' => now()->subMonths(2), 'expires_on' => now()->subWeek(),
        ]);

        $this->assertSame(1, Customer::licenceExpiringWithin(14)->count());
    }

    /** Suspended and ended shops are not chased about a date. */
    public function test_only_live_shops_are_chased(): void
    {
        foreach ([Customer::SUSPENDED, Customer::ENDED] as $status) {
            $customer = Customer::factory()->create(['status' => $status]);
            Licence::factory()->for($customer)->expiringIn(3)->create();
        }

        $this->assertSame(0, Customer::licenceExpiringWithin(14)->count());
    }

    /** An outright sale never needs chasing. */
    public function test_a_perpetual_shop_is_never_chased(): void
    {
        $customer = Customer::factory()->create();
        Licence::factory()->for($customer)->perpetual()->create();

        $this->assertSame(0, Customer::licenceExpiringWithin(3650)->count());
    }
}
