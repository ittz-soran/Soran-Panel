<?php

namespace Tests\Feature;

use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\Licence;
use App\Models\Payment;
use App\Models\User;
use App\Support\ShopReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Renew — PANEL_DOC Section 6, the whole eight steps.
 *
 * The shop here is a real folder with a real `.env`, written by the real
 * LocalShopWriter, because what a licence delivery does to that file is the
 * dangerous part. What is faked is only the shop's ANSWER — the reader — since
 * the shop system is another repository and running it is its own suite's job.
 *
 * The heart of this file is step 7. A licence written into a file is not a
 * licence that works, and `delivered_at` is set from the shop's own word or not
 * at all.
 */
class RenewTest extends TestCase
{
    use RefreshDatabase;

    private static string $private = '';

    private static string $public = '';

    private string $home;

    private Customer $customer;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, self::$private);
        self::$public = openssl_pkey_get_details($resource)['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['licence.public_key' => self::$public]);
        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->home = sys_get_temp_dir().'/shop-renew-'.bin2hex(random_bytes(6));
        mkdir($this->home, 0755, true);

        // What `shop:provision --trial` leaves: a blank public key, which is
        // what makes a trial run unlicensed — and the trap of Section 6.
        file_put_contents($this->home.'/.env', implode("\n", [
            'APP_NAME="Hawler Computer"',
            'DB_DATABASE=hawler_shop',
            'LICENCE_PUBLIC_KEY=',
            'LICENCE_KEY=',
            'STORAGE_LIMIT_MB=2048',
        ])."\n");

        $this->customer = Customer::factory()->create([
            'name' => 'Hawler Computer',
            'host' => 'hawler.soranstore.com',
            'shop_home' => $this->home,
            'status' => Customer::TRIAL,
            'monthly_fee' => 50000,
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

    private function sign(array $payload = [], ?string $key = null): string
    {
        $body = json_encode([
            'id' => 'K7QP-3MZX',
            'shop' => 'Hawler Computer',
            'host' => 'hawler.soranstore.com',
            'issued' => now()->toDateString(),
            'expires' => now()->addMonth()->toDateString(),
            ...$payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        openssl_sign($body, $signature, openssl_pkey_get_private($key ?? self::$private), OPENSSL_ALGO_SHA256);

        $encode = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $encode($body).'.'.$encode($signature);
    }

    /** The shop's answer at step 7 — the only thing faked here. */
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

    private function renew(array $fields = []): TestResponse
    {
        return $this->post(route('customers.renew.store', $this->customer), [
            'licence' => $this->sign(),
            ...$fields,
        ]);
    }

    // ---- The screen -------------------------------------------------------

    public function test_the_screen_shows_the_command_to_run_on_soran_s_own_machine(): void
    {
        config(['licence.private_key_path' => 'C:\soran-keys\private.pem']);

        $this->get(route('customers.renew', $this->customer))
            ->assertOk()
            ->assertSee('licence:issue')
            ->assertSee('--host=hawler.soranstore.com')
            ->assertSee('C:\soran-keys\private.pem')
            ->assertSee('Run this on your own computer');
    }

    public function test_renewing_needs_signing_in(): void
    {
        auth()->logout();

        $this->get(route('customers.renew', $this->customer))->assertRedirect(route('login'));
        $this->post(route('customers.renew.store', $this->customer), [])->assertRedirect(route('login'));
    }

    // ---- Step 3: refused before anything is written -----------------------

    public function test_a_licence_for_another_shop_is_refused_and_nothing_is_touched(): void
    {
        $this->shopSays('valid');
        $before = $this->env();

        $this->renew(['licence' => $this->sign(['host' => 'halabja.soranstore.com'])])
            ->assertSessionHas('warning');

        $this->assertSame($before, $this->env(), "the shop's .env was written despite a refusal");
        $this->assertSame(0, Licence::count());
        $this->assertFileDoesNotExist($this->home.'/.env.bak');
    }

    public function test_a_licence_signed_by_somebody_else_is_refused(): void
    {
        $this->shopSays('valid');

        $other = '';
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $other);

        $this->renew(['licence' => $this->sign(key: $other)])->assertSessionHas('warning');

        $this->assertSame(0, Licence::count());
    }

    public function test_an_expired_licence_is_refused_rather_than_locking_the_shop_out(): void
    {
        $this->shopSays('valid');

        $this->renew(['licence' => $this->sign(['expires' => now()->subDay()->toDateString()])])
            ->assertSessionHas('warning');

        $this->assertSame(0, Licence::count());
    }

    /** A refusal is worth recording: it is somebody pasting the wrong thing. */
    public function test_a_refusal_is_logged(): void
    {
        $this->shopSays('valid');
        $this->renew(['licence' => 'obviously not a licence']);

        $logged = Action::where('action', 'licence.refused')->first();

        $this->assertNotNull($logged);
        $this->assertSame($this->customer->id, $logged->customer_id);
    }

    /** A 400-character paste must not have to be fetched twice. */
    public function test_a_refused_paste_comes_back_in_the_form(): void
    {
        $this->shopSays('valid');
        $wrong = $this->sign(['host' => 'halabja.soranstore.com']);

        $this->renew(['licence' => $wrong])->assertSessionHasInput('licence', $wrong);
    }

    // ---- Steps 4 to 7: the happy path -------------------------------------

    public function test_a_good_licence_is_written_recorded_and_confirmed(): void
    {
        $this->shopSays('valid');

        $this->renew()->assertRedirect(route('customers.show', $this->customer))->assertSessionHas('success');

        $licence = Licence::first();

        $this->assertSame('K7QP-3MZX', $licence->licence_id);
        $this->assertSame('hawler.soranstore.com', $licence->host);
        $this->assertNotNull($licence->delivered_at, 'the shop confirmed and it was not recorded as delivered');
        $this->assertStringContainsString('LICENCE_KEY='.$this->sign(), $this->env());
    }

    /**
     * The trap Section 6 records. Writing a LICENCE_KEY while the blank
     * LICENCE_PUBLIC_KEY is still there leaves the licence unchecked, which is
     * the same as no licence at all.
     */
    public function test_the_blank_public_key_a_trial_leaves_is_removed(): void
    {
        $this->shopSays('valid');

        $this->assertStringContainsString('LICENCE_PUBLIC_KEY=', $this->env());

        $this->renew();

        $this->assertStringNotContainsString('LICENCE_PUBLIC_KEY', $this->env());
    }

    /** Section 6, step 5. */
    public function test_the_old_env_is_kept(): void
    {
        $this->shopSays('valid');
        $before = $this->env();

        $this->renew();

        $this->assertSame($before, file_get_contents($this->home.'/.env.bak'));
    }

    public function test_delivering_a_real_licence_ends_the_trial(): void
    {
        $this->shopSays('valid');

        $this->assertSame(Customer::TRIAL, $this->customer->status);

        $this->renew();

        $this->assertSame(Customer::ACTIVE, $this->customer->fresh()->status);
    }

    /** A renewal is a new row, and the one it replaces says so. */
    public function test_the_licence_it_replaces_is_marked_replaced(): void
    {
        $this->shopSays('valid');

        $old = Licence::factory()->for($this->customer)->create([
            'licence_id' => 'OLD1-AAAA', 'issued_on' => now()->subMonth(),
        ]);

        $this->renew();

        $old = $old->fresh();

        $this->assertNotNull($old->revoked_at);
        $this->assertStringContainsString('K7QP-3MZX', $old->revoked_reason);
        $this->assertSame('K7QP-3MZX', $this->customer->fresh()->currentLicence->licence_id);
        $this->assertSame(2, $this->customer->licences()->count(), 'every licence ever issued is kept');
    }

    public function test_delivery_is_logged_with_who_did_it(): void
    {
        $this->shopSays('valid');
        $this->renew();

        $logged = Action::where('action', 'licence.delivered')->first();

        $this->assertNotNull($logged);
        $this->assertSame(auth()->id(), $logged->user_id);
        $this->assertSame('K7QP-3MZX', $logged->detail['licence']);
        $this->assertSame('valid', $logged->detail['shop_says']);
    }

    // ---- Step 7: the part that matters ------------------------------------

    /**
     * A licence in a file is not a licence that works. If the shop does not
     * agree, `delivered_at` stays null however perfectly the write went.
     */
    public function test_a_shop_that_does_not_agree_leaves_the_licence_undelivered(): void
    {
        $this->shopSays('unlicensed');

        $this->renew()->assertSessionHas('warning');

        $this->assertNull(Licence::first()->delivered_at);
        $this->assertNull($this->customer->fresh()->currentLicence, 'an unconfirmed licence must not read as live');
    }

    public function test_the_warning_says_what_the_shop_actually_reported(): void
    {
        $this->shopSays('unlicensed');

        $this->renew();

        $this->assertStringContainsString('LICENCE_PUBLIC_KEY is blank', session('warning'));
    }

    public function test_a_shop_reporting_wrong_host_is_explained(): void
    {
        $this->shopSays('wrong_host');

        $this->renew();

        $this->assertStringContainsString('different domain', session('warning'));
        $this->assertNull(Licence::first()->delivered_at);
    }

    /** Could not be asked is not the same as did not work. */
    public function test_a_shop_that_could_not_be_asked_is_not_assumed_to_be_fine(): void
    {
        $this->shopSays(null);

        $this->renew()->assertSessionHas('warning');

        $this->assertNull(Licence::first()->delivered_at);
        $this->assertStringContainsString('could not be asked', session('warning'));
    }

    /** The write failing must still leave a record of what was issued. */
    public function test_a_shop_whose_env_cannot_be_written_keeps_the_licence_on_record(): void
    {
        $this->shopSays('valid');
        unlink($this->home.'/.env');

        $this->renew()->assertSessionHas('warning');

        $this->assertSame(1, Licence::count());
        $this->assertNull(Licence::first()->delivered_at);
        $this->assertNotNull(Action::where('action', 'licence.delivery_failed')->first());
    }

    // ---- Step 8: the payment ----------------------------------------------

    public function test_a_payment_is_recorded_when_asked(): void
    {
        $this->shopSays('valid');

        $this->renew([
            'record_payment' => 1,
            'amount' => 150000,
            'covers_from' => now()->toDateString(),
            'covers_to' => now()->addMonths(3)->subDay()->toDateString(),
            'method' => 'FIB',
        ])->assertSessionHas('success');

        $payment = Payment::first();

        $this->assertSame(150000, $payment->amount);
        $this->assertSame('FIB', $payment->method);
        $this->assertSame(3, $payment->monthsCovered());
        $this->assertSame(auth()->id(), $payment->recorded_by);
    }

    public function test_no_payment_is_recorded_when_not_asked(): void
    {
        $this->shopSays('valid');

        $this->renew()->assertSessionHas('success');

        $this->assertSame(0, Payment::count());
    }

    /** Money taken with no licence recorded is a conversation nobody can reconstruct. */
    public function test_a_payment_is_not_recorded_when_the_licence_is_refused(): void
    {
        $this->shopSays('valid');

        $this->renew([
            'licence' => $this->sign(['host' => 'somebody-else.soranstore.com']),
            'record_payment' => 1, 'amount' => 150000,
            'covers_from' => now()->toDateString(), 'covers_to' => now()->addMonth()->toDateString(),
        ]);

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Licence::count());
    }

    public function test_a_payment_with_no_amount_is_refused(): void
    {
        $this->shopSays('valid');

        $this->renew(['record_payment' => 1, 'amount' => null])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Licence::count(), 'nothing should be delivered on a form that did not validate');
    }
}
