<?php

namespace Tests\Feature;

use App\Contracts\ShopReader;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Support\ShopReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The hourly check — PANEL_DOC Section 5.
 *
 * The reader is replaced here, because what this command is responsible for is
 * not reading a shop but what it does with the answers: a row per shop per run,
 * kept rather than overwritten, and never stopping early. ShopReaderTest holds
 * the reading itself.
 */
class ShopsCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private function readerReturning(callable $answer): void
    {
        $this->swap(ShopReader::class, new class($answer) implements ShopReader
        {
            public function __construct(private $answer) {}

            public function read(Customer $customer): ShopReading
            {
                return ($this->answer)($customer);
            }
        });
    }

    private function healthy(): ShopReading
    {
        return new ShopReading(
            reachable: true,
            databaseBytes: 40 * 1024 * 1024,
            backupsBytes: 0,
            uploadsBytes: 0,
            storageLimitMb: 2048,
            migrationsRun: 32,
            migrationsTotal: 32,
            usersCount: 3,
            productsCount: 412,
            salesCount: 1875,
            licenceState: 'valid',
            dataCheckPassed: 17,
            dataCheckTotal: 17,
        );
    }

    public function test_it_records_a_snapshot_for_every_live_shop(): void
    {
        Customer::factory()->count(2)->create();
        Customer::factory()->trial()->create();
        Customer::factory()->suspended()->create();
        Customer::factory()->ended()->create();

        $this->readerReturning(fn () => $this->healthy());

        $this->artisan('shops:check')->assertSuccessful();

        $this->assertSame(3, HealthCheck::count(), 'live shops only — trial and active');
    }

    public function test_all_includes_the_suspended_and_the_ended(): void
    {
        Customer::factory()->create();
        Customer::factory()->suspended()->create();

        $this->readerReturning(fn () => $this->healthy());

        $this->artisan('shops:check', ['--all' => true])->assertSuccessful();

        $this->assertSame(2, HealthCheck::count());
    }

    public function test_one_shop_can_be_looked_at_by_host(): void
    {
        $bazaar = Customer::factory()->create(['host' => 'bazaar.soranstore.com']);
        Customer::factory()->create();

        $this->readerReturning(fn () => $this->healthy());

        $this->artisan('shops:check', ['customer' => 'bazaar.soranstore.com'])->assertSuccessful();

        $this->assertSame(1, HealthCheck::count());
        $this->assertSame($bazaar->id, HealthCheck::first()->customer_id);
    }

    public function test_what_the_reader_found_is_what_gets_written_down(): void
    {
        $customer = Customer::factory()->create();
        $this->readerReturning(fn () => $this->healthy());

        $this->artisan('shops:check')->assertSuccessful();

        $check = HealthCheck::first();

        $this->assertTrue($check->reachable);
        $this->assertSame(412, $check->products_count);
        $this->assertSame('valid', $check->licence_state);
        $this->assertSame(17, $check->data_check_passed);
        $this->assertNull($check->error);
        $this->assertNotNull($check->checked_at);
    }

    /**
     * Snapshots, not a column. Yesterday's reading is still there to compare
     * against — the whole reason Section 5 puts these in a table.
     */
    public function test_a_second_run_adds_a_row_rather_than_replacing_one(): void
    {
        $customer = Customer::factory()->create();
        $this->readerReturning(fn () => $this->healthy());

        $this->artisan('shops:check');
        $this->artisan('shops:check');

        $this->assertSame(2, $customer->healthChecks()->count());
    }

    /** A monitor that gives up on the first problem stops being a monitor. */
    public function test_one_broken_shop_does_not_stop_the_others_being_looked_at(): void
    {
        $broken = Customer::factory()->create(['host' => 'aaa.soranstore.com']);
        $fine = Customer::factory()->create(['host' => 'zzz.soranstore.com']);

        $this->readerReturning(fn (Customer $c) => $c->is($broken)
            ? new ShopReading(reachable: false, problems: ['Its database would not answer.'])
            : $this->healthy());

        $this->artisan('shops:check')->assertSuccessful();

        $this->assertSame(2, HealthCheck::count());
        $this->assertFalse($broken->healthChecks()->first()->reachable);
        $this->assertTrue($fine->healthChecks()->first()->reachable);
    }

    /**
     * The reader promises not to throw. If it breaks that promise, that is
     * still this shop's problem and not the next shop's.
     */
    public function test_a_reader_that_throws_is_recorded_and_the_run_continues(): void
    {
        $throws = Customer::factory()->create(['host' => 'aaa.soranstore.com']);
        $fine = Customer::factory()->create(['host' => 'zzz.soranstore.com']);

        $this->readerReturning(function (Customer $c) use ($throws) {
            if ($c->is($throws)) {
                throw new RuntimeException('the reader itself fell over');
            }

            return $this->healthy();
        });

        $this->artisan('shops:check')->assertSuccessful();

        $this->assertSame(2, HealthCheck::count());
        $this->assertStringContainsString(
            'the reader itself fell over',
            $throws->healthChecks()->first()->error,
        );
    }

    /**
     * Shops being down is what this command reports. Exiting non-zero would
     * have cron email Soran about the cron job instead of about the shop.
     */
    public function test_unreachable_shops_are_not_a_failed_run(): void
    {
        Customer::factory()->create();
        $this->readerReturning(fn () => new ShopReading(reachable: false, problems: ['down']));

        $this->artisan('shops:check')
            ->expectsOutputToContain('unreachable')
            ->assertSuccessful();
    }

    public function test_it_says_so_when_there_is_nothing_to_look_at(): void
    {
        $this->artisan('shops:check')->assertSuccessful();

        $this->assertSame(0, HealthCheck::count());
    }

    /** Something that went wrong without making the shop unreachable is still said out loud. */
    public function test_a_problem_that_did_not_stop_the_reading_is_still_reported(): void
    {
        Customer::factory()->create();

        $this->readerReturning(fn () => new ShopReading(
            reachable: true,
            licenceState: 'valid',
            problems: ['Its migrate:status said nothing this could count.'],
        ));

        $this->artisan('shops:check')
            ->expectsOutputToContain('migrate:status said nothing')
            ->assertSuccessful();

        $this->assertStringContainsString('migrate:status', HealthCheck::first()->error);
    }
}
