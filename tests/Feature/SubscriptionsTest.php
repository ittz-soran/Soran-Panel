<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Customer;
use App\Models\Licence;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who has paid, who has not, what the month is worth — PANEL_DOC Section 9.
 *
 * The thing held hardest here is the separation between a LICENCE and a
 * PAYMENT. A licence is what a shop may run on; a payment is money that
 * actually arrived. They come apart exactly when it matters, and a screen that
 * read the licence would show a customer as settled because they can still
 * trade.
 */
class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));
    }

    private function paid(Customer $customer, string $from, string $to, int $amount = 50000): Payment
    {
        return Payment::factory()->for($customer)->create([
            'amount' => $amount,
            'paid_on' => $from,
            'covers_from' => $from,
            'covers_to' => $to,
        ]);
    }

    public function test_the_screens_need_signing_in(): void
    {
        auth()->logout();
        $customer = Customer::factory()->create();

        $this->get(route('subscriptions.index'))->assertRedirect(route('login'));
        $this->post(route('subscriptions.store', $customer), [])->assertRedirect(route('login'));
    }

    // ---- Who has paid, who has not ---------------------------------------

    public function test_a_customer_paid_into_the_future_is_not_owing(): void
    {
        $customer = Customer::factory()->create();
        $this->paid($customer, now()->subDays(5)->toDateString(), now()->addMonth()->toDateString());

        $this->assertSame(0, Customer::owing()->count());
        $this->assertSame(0, $customer->monthsOwed());
        $this->assertNull($customer->daysLate());
    }

    public function test_a_customer_whose_period_has_run_out_is_owing(): void
    {
        $customer = Customer::factory()->create();
        $this->paid($customer, now()->subMonths(2)->toDateString(), now()->subWeek()->toDateString());

        $this->assertSame(1, Customer::owing()->count());
        $this->assertSame(7, $customer->daysLate());
    }

    /** A customer who has never paid is owing, without needing a second clause. */
    public function test_a_customer_who_has_never_paid_is_owing(): void
    {
        Customer::factory()->create(['started_on' => now()->subMonths(3)]);

        $this->assertSame(1, Customer::owing()->count());
    }

    /** Paying early is paying. */
    public function test_a_payment_for_next_month_counts_today(): void
    {
        $customer = Customer::factory()->create();
        $this->paid($customer, now()->addMonth()->toDateString(), now()->addMonths(2)->toDateString());

        $this->assertSame(0, Customer::owing()->count());
    }

    public function test_suspended_and_ended_customers_are_not_chased_for_money(): void
    {
        Customer::factory()->suspended()->create(['started_on' => now()->subYear()]);
        Customer::factory()->ended()->create(['started_on' => now()->subYear()]);

        $this->assertSame(0, Customer::owing()->count());
    }

    /**
     * A shop trading on a licence it never paid for is exactly the case this
     * screen exists to surface.
     */
    public function test_a_licence_does_not_make_somebody_paid_up(): void
    {
        $customer = Customer::factory()->create(['started_on' => now()->subMonths(2)]);
        Licence::factory()->for($customer)->create(['expires_on' => now()->addYear()]);

        $this->assertSame(1, Customer::owing()->count());

        $this->get(route('subscriptions.index'))
            ->assertOk()
            ->assertSee('never paid');
    }

    public function test_what_is_owed_is_months_times_the_fee(): void
    {
        $customer = Customer::factory()->create(['monthly_fee' => 50000]);
        $this->paid($customer, now()->subMonths(4)->toDateString(), now()->subMonths(3)->toDateString());

        $this->assertSame(3, $customer->monthsOwed());
        $this->assertSame(150000, $customer->owes());
    }

    /** A week late is a month's invoice — that is how the business works. */
    public function test_a_few_days_late_is_still_one_month(): void
    {
        $customer = Customer::factory()->create(['monthly_fee' => 50000]);
        $this->paid($customer, now()->subMonth()->toDateString(), now()->subDays(3)->toDateString());

        $this->assertSame(1, $customer->monthsOwed());
        $this->assertSame(50000, $customer->owes());
    }

    // ---- What the month is worth -----------------------------------------

    public function test_the_screen_totals_the_month_and_what_came_in(): void
    {
        $a = Customer::factory()->create(['monthly_fee' => 50000]);
        $b = Customer::factory()->create(['monthly_fee' => 75000]);
        Customer::factory()->ended()->create(['monthly_fee' => 90000]);

        $this->paid($a, now()->toDateString(), now()->addMonth()->toDateString(), 50000);
        $this->paid($b, now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            now()->subMonthNoOverflow()->endOfMonth()->toDateString(), 75000);

        $this->get(route('subscriptions.index'))
            ->assertOk()
            ->assertSee('125,000 IQD')   // a month, live shops only
            ->assertSee('50,000 IQD')    // came in this month
            ->assertSee('75,000 IQD')    // last month
            ->assertDontSee('215,000');
    }

    public function test_the_owing_filter_shows_only_those_behind(): void
    {
        $behind = Customer::factory()->create(['name' => 'Behind Shop', 'started_on' => now()->subYear()]);
        $fine = Customer::factory()->create(['name' => 'Settled Shop']);
        $this->paid($fine, now()->toDateString(), now()->addMonth()->toDateString());

        $this->get(route('subscriptions.index'))->assertOk()->assertSee('Behind Shop')->assertSee('Settled Shop');

        $this->get(route('subscriptions.index', ['show' => 'owing']))
            ->assertOk()
            ->assertSee('Behind Shop')
            ->assertDontSee('Settled Shop');
    }

    public function test_the_list_does_not_run_a_query_per_customer(): void
    {
        foreach (range(1, 6) as $i) {
            $customer = Customer::factory()->create();
            $this->paid($customer, now()->subMonth()->toDateString(), now()->addMonth()->toDateString());
        }

        \DB::enableQueryLog();
        $this->get(route('subscriptions.index'))->assertOk();
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "the list ran {$queries} queries for six customers");
    }

    // ---- Recording ---------------------------------------------------------

    public function test_a_payment_can_be_recorded(): void
    {
        $customer = Customer::factory()->create(['monthly_fee' => 50000]);

        $this->post(route('subscriptions.store', $customer), [
            'amount' => 150000,
            'paid_on' => now()->toDateString(),
            'covers_from' => now()->toDateString(),
            'covers_to' => now()->addMonths(3)->subDay()->toDateString(),
            'method' => 'FIB',
            'reference' => 'TX-9912',
        ])->assertSessionHas('success');

        $payment = Payment::firstOrFail();

        $this->assertSame(150000, $payment->amount);
        $this->assertSame('FIB', $payment->method);
        $this->assertSame(3, $payment->monthsCovered());
        $this->assertSame(auth()->id(), $payment->recorded_by);
        $this->assertSame(0, Customer::owing()->count());
    }

    public function test_recording_a_payment_is_logged(): void
    {
        $customer = Customer::factory()->create();

        $this->post(route('subscriptions.store', $customer), [
            'amount' => 50000, 'paid_on' => now()->toDateString(),
            'covers_from' => now()->toDateString(), 'covers_to' => now()->addMonth()->toDateString(),
        ]);

        $logged = Action::where('action', 'payment.recorded')->first();

        $this->assertSame($customer->id, $logged->customer_id);
        $this->assertSame(auth()->id(), $logged->user_id);
        $this->assertSame(50000, $logged->detail['amount']);
    }

    public function test_money_cannot_have_arrived_in_the_future(): void
    {
        $customer = Customer::factory()->create();

        $this->post(route('subscriptions.store', $customer), [
            'amount' => 50000, 'paid_on' => now()->addWeek()->toDateString(),
            'covers_from' => now()->toDateString(), 'covers_to' => now()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('paid_on');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        $customer = Customer::factory()->create();

        $this->post(route('subscriptions.store', $customer), [
            'amount' => 50000, 'paid_on' => now()->toDateString(),
            'covers_from' => now()->toDateString(), 'covers_to' => now()->subWeek()->toDateString(),
        ])->assertSessionHasErrors('covers_to');
    }

    /** Whole dinars — PROJECT_DOC Section 2. */
    public function test_a_decimal_amount_is_refused(): void
    {
        $customer = Customer::factory()->create();

        $this->post(route('subscriptions.store', $customer), [
            'amount' => '50000.50', 'paid_on' => now()->toDateString(),
            'covers_from' => now()->toDateString(), 'covers_to' => now()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('amount');
    }

    // ---- Correcting and removing ------------------------------------------

    public function test_a_payment_can_be_corrected_and_the_change_is_logged(): void
    {
        $customer = Customer::factory()->create();
        $payment = $this->paid($customer, now()->toDateString(), now()->addMonth()->toDateString(), 50000);

        $this->put(route('subscriptions.update', [$customer, $payment]), [
            'amount' => 75000,
            'paid_on' => $payment->paid_on->toDateString(),
            'covers_from' => $payment->covers_from->toDateString(),
            'covers_to' => $payment->covers_to->toDateString(),
        ])->assertSessionHas('success');

        $this->assertSame(75000, $payment->fresh()->amount);

        $logged = Action::where('action', 'payment.corrected')->first();

        $this->assertSame(50000, $logged->detail['from']['amount']);
        $this->assertSame(75000, $logged->detail['to']['amount']);
    }

    /** A payment that can vanish is a payment somebody can deny receiving. */
    public function test_a_removed_payment_stops_counting_but_stays_on_record(): void
    {
        $customer = Customer::factory()->create(['started_on' => now()->subYear()]);
        $payment = $this->paid($customer, now()->toDateString(), now()->addMonth()->toDateString());

        $this->assertSame(0, Customer::owing()->count());

        $this->delete(route('subscriptions.destroy', [$customer, $payment]))->assertSessionHas('warning');

        $this->assertSame(1, Customer::owing()->count(), 'a removed payment still counted');
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, Payment::withTrashed()->count());
        $this->assertNotNull(Action::where('action', 'payment.removed')->first());
    }

    public function test_a_removed_payment_can_be_counted_again(): void
    {
        $customer = Customer::factory()->create();
        $payment = $this->paid($customer, now()->toDateString(), now()->addMonth()->toDateString());
        $payment->delete();

        $this->post(route('subscriptions.restore', [$customer, $payment->id]))->assertSessionHas('success');

        $this->assertSame(1, Payment::count());
        $this->assertSame(0, Customer::owing()->count());
    }

    /** A payment belongs to one customer, and cannot be touched through another. */
    public function test_a_payment_cannot_be_removed_through_the_wrong_customer(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $payment = $this->paid($mine, now()->toDateString(), now()->addMonth()->toDateString());

        $this->delete(route('subscriptions.destroy', [$theirs, $payment]))->assertNotFound();

        $this->assertSame(1, Payment::count());
    }

    // ---- The screens ------------------------------------------------------

    public function test_a_customers_payments_are_all_listed(): void
    {
        $customer = Customer::factory()->create(['name' => 'Hawler Computer']);
        $this->paid($customer, '2026-06-01', '2026-06-30', 50000);
        $this->paid($customer, '2026-07-01', '2026-09-30', 150000);

        $this->get(route('subscriptions.show', $customer))
            ->assertOk()
            ->assertSee('Hawler Computer')
            ->assertSee('50,000')
            ->assertSee('150,000')
            ->assertSee('Record a payment');
    }

    public function test_removed_payments_are_shown_apart_from_the_counted_ones(): void
    {
        $customer = Customer::factory()->create();
        $this->paid($customer, '2026-06-01', '2026-06-30', 11111)->delete();

        $this->get(route('subscriptions.show', $customer))
            ->assertOk()
            ->assertSee('Removed')
            ->assertSee('11,111')
            ->assertSee('Count it again');
    }

    public function test_the_screen_says_so_when_everybody_is_paid_up(): void
    {
        $customer = Customer::factory()->create();
        $this->paid($customer, now()->toDateString(), now()->addMonth()->toDateString());

        $this->get(route('subscriptions.index', ['show' => 'owing']))
            ->assertOk()
            ->assertSee('Everybody is paid up');
    }

    /**
     * A shop that has stopped trading owes nothing.
     *
     * The `owing` scope already left them out, and the model did not — so an
     * ended customer was off the Owing filter and still shown as "24 months"
     * on the row beside it. Seen on the screen, not in a test.
     */
    public function test_a_shop_that_has_stopped_trading_owes_nothing(): void
    {
        foreach ([Customer::SUSPENDED, Customer::ENDED] as $status) {
            $customer = Customer::factory()->create([
                'status' => $status,
                'started_on' => now()->subYears(2),
                'monthly_fee' => 75000,
            ]);

            $this->assertSame(0, $customer->monthsOwed(), "a {$status} shop was still being counted");
            $this->assertSame(0, $customer->owes());
        }

        $this->assertSame(0, Customer::owing()->count());
    }

    /** The row and the filter must never disagree about who owes. */
    public function test_the_months_shown_and_the_owing_filter_agree(): void
    {
        Customer::factory()->create(['started_on' => now()->subMonths(3)]);
        Customer::factory()->ended()->create(['started_on' => now()->subYears(2), 'monthly_fee' => 75000]);

        $counted = Customer::all()->filter(fn (Customer $c) => $c->monthsOwed() > 0);

        $this->assertSame(
            Customer::owing()->pluck('id')->sort()->values()->all(),
            $counted->pluck('id')->sort()->values()->all(),
        );
    }
}
