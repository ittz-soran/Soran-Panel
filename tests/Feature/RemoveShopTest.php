<?php

namespace Tests\Feature;

use App\Contracts\DatabaseMaker;
use App\Contracts\DnsMaker;
use App\Contracts\DomainMaker;
use App\Models\Action;
use App\Models\Customer;
use App\Models\Licence;
use App\Models\Payment;
use App\Models\User;
use App\Services\ShopRemover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Removing a shop for good — PANEL_DOC Section 7.
 *
 * Section 7 said for most of this panel's life that it may **never** delete a
 * shop's database. Soran changed that rule on purpose, and this file is where
 * the new rule is held to: **the database goes, and only ever after a dump of
 * it has been taken and copied somewhere the removal cannot reach.**
 *
 * So the test that matters most here is not that removal works. It is that
 * every way the backup can fail leaves the shop completely untouched — because
 * the one outcome nobody recovers from is a database dropped after a backup
 * that did not happen.
 */
class RemoveShopTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    /** What each spy was asked to remove, in the order it was asked. */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->root = sys_get_temp_dir().'/remove-'.bin2hex(random_bytes(6));

        mkdir($this->root.'/shops', 0755, true);
        mkdir($this->root.'/public_html', 0755, true);
        mkdir($this->root.'/removed-shops', 0755, true);

        config([
            'panel.shops.home_root' => $this->root.'/shops',
            'panel.shops.public_root' => $this->root.'/public_html',
            'panel.shops.removed_root' => $this->root.'/removed-shops',
        ]);

        $asked = &$this->asked;

        $this->swap(DatabaseMaker::class, new class($asked) implements DatabaseMaker
        {
            public function __construct(public array &$asked, public bool $refuse = false) {}

            public function realName(string $wanted): string
            {
                return $wanted;
            }

            public function create(string $database, string $user, string $password): void
            {
                throw new RuntimeException('Removing a shop must never create a database.');
            }

            public function drop(string $database, string $user): array
            {
                $this->asked[] = "database:{$database}";

                return $this->refuse ? ["the database [{$database}]"] : [];
            }
        });

        $this->swap(DomainMaker::class, new class($asked) implements DomainMaker
        {
            public bool $automatic = true;

            /** What it could not take off, as CpanelDomainMaker reports it. */
            public array $leaves = [];

            public function __construct(public array &$asked) {}

            public function create(string $host, string $documentRoot): void {}

            public function remove(string $host): array
            {
                $this->asked[] = "domain:{$host}";

                return $this->leaves;
            }

            public function secure(string $host): ?string
            {
                return null;
            }

            public function describe(): string
            {
                return 'a spy';
            }

            public function isAutomatic(): bool
            {
                return $this->automatic;
            }
        });

        $this->swap(DnsMaker::class, new class($asked) implements DnsMaker
        {
            public bool $automatic = true;

            public function __construct(public array &$asked) {}

            public function create(string $host, string $address): void {}

            public function remove(string $host): array
            {
                $this->asked[] = "dns:{$host}";

                return [];
            }

            public function describe(): string
            {
                return 'a spy';
            }

            public function verify(): string
            {
                return 'a spy';
            }

            public function isAutomatic(): bool
            {
                return $this->automatic;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root ?? '');

        parent::tearDown();
    }

    /**
     * A shop on disk: both folders, an artisan that can dump itself, and a
     * backup folder with something in it.
     */
    private function shop(array $attributes = [], string $short = 'bazaar'): Customer
    {
        $home = $this->root.'/shops/'.$short;
        $public = $this->root.'/public_html/'.$short;

        mkdir($home.'/storage/app/backups/daily', 0755, true);
        mkdir($public, 0755, true);

        file_put_contents($home.'/.env', "APP_NAME=Bazaar\n");
        file_put_contents($public.'/index.php', '<?php');

        // Its own artisan, as `shop:provision` leaves it. `backup:run` writes a
        // dump into its own backups folder, which is what the real one does and
        // is the reason the copy has to happen before the folder goes.
        file_put_contents($home.'/artisan', "<?php\n\$home = ".var_export($home, true).";\n".<<<'PHP'
        if (($argv[1] ?? '') === 'backup:run') {
            if (getenv('DUMP_MUST_FAIL')) { fwrite(STDERR, 'mysqldump is not installed'); exit(1); }
            if (getenv('DUMP_MUST_VANISH')) { echo "Backed up.\n"; exit(0); }

            // Exactly where and how the real one writes: BackupService puts the
            // file in a `daily` folder under the backups path, and BackupRun
            // prints the full path indented, with the size after it.
            $path = $home.'/storage/app/backups/daily/backup-2026-09-06-023000.sql.gz';
            file_put_contents($path, str_repeat('INSERT;', 100));

            echo "Backing up…\n";
            if (! getenv('DUMP_SAYS_NOTHING')) { echo '  '.$path."  (700 B)\n"; }
            exit(0);
        }
        exit(0);
        PHP);

        chmod($home.'/artisan', 0755);

        return Customer::factory()->create([
            'name' => 'Bazaar',
            'host' => $short.'.soranstore.com',
            'shop_home' => $home,
            'public_path' => $public,
            'database_name' => 'soransto_'.$short.'_shop',
            'database_user' => 'soransto_'.$short.'_user',
            'status' => Customer::SUSPENDED,
            ...$attributes,
        ]);
    }

    private function remover(): ShopRemover
    {
        return app(ShopRemover::class);
    }

    /**
     * The message `remove()` refused with.
     *
     * Not a bare try/catch around `$this->fail()`: PHPUnit's own exceptions
     * extend RuntimeException, so the catch swallows the failure and the test
     * then asserts against its own "this should not have happened" message —
     * which passes or fails for reasons that have nothing to do with the panel.
     * That happened while writing this file.
     */
    private function refusalOf(Customer $customer): string
    {
        try {
            $this->remover()->remove($customer);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('the shop was removed, and it should not have been');
    }

    /**
     * A variable the shop's own artisan will actually see.
     *
     * `putenv` alone is not enough: Symfony builds a child's environment from
     * `$_ENV` and `$_SERVER`, not from `getenv()`, so a putenv-only flag never
     * arrives and the test quietly exercises the success path instead of the
     * failure it is named after. Both of these did exactly that before this
     * helper existed — they passed the removal and reported it as a refusal.
     */
    private function withEnvironment(string $key, callable $body): void
    {
        putenv("{$key}=1");
        $_ENV[$key] = $_SERVER[$key] = '1';

        try {
            $body();
        } finally {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /** Everything a removal should have taken, still standing. */
    private function assertNothingWasTouched(Customer $customer): void
    {
        $this->assertSame([], $this->asked, 'a maker was asked to remove something');
        $this->assertDirectoryExists($customer->shop_home);
        $this->assertDirectoryExists($customer->public_path);
        $this->assertFalse($customer->fresh()->trashed(), 'the customer row was removed anyway');
    }

    // ---------------------------------------------------------------- the rule

    public function test_a_trading_shop_cannot_be_removed_at_all(): void
    {
        foreach ([Customer::ACTIVE, Customer::TRIAL] as $status) {
            $customer = $this->shop(['status' => $status], short: $status);

            $this->assertStringContainsString('Suspend it first', (string) $this->remover()->blocked($customer));
            $this->assertStringContainsString('Suspend it first', $this->refusalOf($customer));

            $this->assertNothingWasTouched($customer);
        }
    }

    public function test_a_suspended_shop_may_be_removed(): void
    {
        $this->assertNull($this->remover()->blocked($this->shop()));
    }

    // ------------------------------------------------- the backup is the gate

    public function test_the_database_is_dumped_and_the_dump_is_copied_out_of_the_shop(): void
    {
        $customer = $this->shop();

        $result = $this->remover()->remove($customer);

        $this->assertFileExists($result['backup']);
        $this->assertSame(700, filesize($result['backup']), 'the dump did not copy in full');

        // The whole point: it is not inside anything the removal deletes.
        $this->assertStringStartsWith($this->root.'/removed-shops/', $result['backup']);
        $this->assertStringContainsString('bazaar.soranstore.com', basename($result['backup']));
    }

    public function test_a_dump_that_fails_removes_absolutely_nothing(): void
    {
        $customer = $this->shop();

        $said = '';

        $this->withEnvironment('DUMP_MUST_FAIL', function () use ($customer, &$said) {
            $said = $this->refusalOf($customer);
        });

        $this->assertStringContainsString('nothing has been removed', $said);
        $this->assertStringContainsString('mysqldump is not installed', $said,
            'the shop’s own reason for failing was thrown away');

        $this->assertNothingWasTouched($customer);
    }

    /**
     * The nastier one. `backup:run` says it worked and leaves no file — a
     * success exit code is not evidence of a backup, and believing it is how a
     * database gets dropped with nothing to restore from.
     */
    public function test_a_dump_that_succeeds_and_leaves_no_file_removes_nothing(): void
    {
        $customer = $this->shop();

        $said = '';

        $this->withEnvironment('DUMP_MUST_VANISH', function () use ($customer, &$said) {
            $said = $this->refusalOf($customer);
        });

        $this->assertStringContainsString('cannot find what it wrote', $said);

        $this->assertNothingWasTouched($customer);
    }

    /**
     * ⚠️ The bug this found in the field, on the first real removal.
     *
     * The panel looked for the dump directly in `storage/app/backups`, and the
     * shop system does not put it there — `BackupService::directory()` appends
     * `daily`, so it is one level down. Every removal failed with "left no
     * file", naming a folder that did contain the backup.
     *
     * The fix is not a deeper search; it is asking. `backup:run` prints the
     * path it wrote, so the shop is now the one that says where its backup is.
     */
    public function test_the_dump_is_found_where_the_shop_system_really_writes_it(): void
    {
        $customer = $this->shop();

        $result = $this->remover()->remove($customer);

        $this->assertFileExists($result['backup']);
        $this->assertStringContainsString('backup-2026-09-06-023000.sql.gz', $result['backup']);
        $this->assertSame(700, filesize($result['backup']));
    }

    /**
     * And the folder is not fixed either: the shop system reads
     * `setting('backup_path')` and then `BACKUP_PATH` from the shop's own
     * `.env`, so a shop whose backups go to an external drive would never be
     * found by searching its own folder however deep. What it says wins.
     */
    public function test_a_backup_written_outside_the_shop_is_still_followed(): void
    {
        $customer = $this->shop();

        $elsewhere = $this->root.'/an-external-drive';
        mkdir($elsewhere, 0755, true);
        file_put_contents($elsewhere.'/backup-elsewhere.sql.gz', str_repeat('X', 321));

        // A shop whose backup:run puts it somewhere else entirely and says so.
        file_put_contents($customer->shop_home.'/artisan', '<?php
'
            .'if (($argv[1] ?? "") === "backup:run") { echo "  '.$elsewhere.'/backup-elsewhere.sql.gz  (321 B)
"; }'
            .'
exit(0);
');

        $result = $this->remover()->remove($customer);

        $this->assertSame(321, filesize($result['backup']));
        $this->assertStringContainsString('backup-elsewhere', $result['backup']);
    }

    /** If the output ever stops naming a path, the search still has to work. */
    public function test_a_backup_is_still_found_when_the_shop_says_nothing_about_it(): void
    {
        $customer = $this->shop();

        $result = ['backup' => ''];

        $this->withEnvironment('DUMP_SAYS_NOTHING', function () use ($customer, &$result) {
            $result = $this->remover()->remove($customer);
        });

        $this->assertSame(700, filesize($result['backup']),
            'the fallback search did not look inside the daily folder');
    }

    /**
     * The backup must not be kept somewhere the removal deletes.
     *
     * A setting away from being useless: point PANEL_REMOVED_SHOPS inside the
     * shops folder and every dump is destroyed a second later by the removal it
     * was taken for — and it would look like it worked.
     */
    public function test_a_keep_folder_inside_the_shops_is_refused_before_anything_goes(): void
    {
        $customer = $this->shop();

        config(['panel.shops.removed_root' => $this->root.'/shops/backups']);

        $this->assertStringContainsString('insurance against', $this->refusalOf($customer));

        $this->assertNothingWasTouched($customer);
    }

    /** A shop whose folder is already gone cannot be dumped, so it is not dropped. */
    public function test_a_shop_with_no_artisan_is_refused_and_told_how_to_proceed(): void
    {
        $customer = $this->shop();

        unlink($customer->shop_home.'/artisan');

        $this->assertStringContainsString('take it on again first', $this->refusalOf($customer));

        $this->assertSame([], $this->asked);
    }

    // ------------------------------------------------------------- the order

    public function test_it_takes_the_shop_apart_from_the_outside_in(): void
    {
        $customer = $this->shop();

        $this->remover()->remove($customer);

        /*
         * The published name, then the subdomain, then the database. A record
         * or a domain left pointing at a folder that has gone is a live address
         * serving wreckage, and it is the only part of this a stranger sees.
         */
        $this->assertSame([
            'dns:bazaar.soranstore.com',
            'domain:bazaar.soranstore.com',
            'database:soransto_bazaar_shop',
        ], $this->asked);

        $this->assertDirectoryDoesNotExist($customer->shop_home);
        $this->assertDirectoryDoesNotExist($customer->public_path);
    }

    /** Teardown never stops at the first failure — it reports and carries on. */
    public function test_what_could_not_be_removed_is_reported_and_the_rest_still_happens(): void
    {
        $customer = $this->shop();

        app(DatabaseMaker::class)->refuse = true;

        $result = $this->remover()->remove($customer);

        $this->assertSame(['the database [soransto_bazaar_shop]'], $result['left']);

        // And everything else went anyway.
        $this->assertDirectoryDoesNotExist($customer->shop_home);
        $this->assertTrue($customer->fresh()->trashed());
    }

    /**
     * ⚠️ The panel must not say it removed something it does not remove.
     *
     * The DNS line said "by hand" when the panel does not publish names, and
     * the domain line did not — so a panel with PANEL_DOMAIN_MAKER=manual
     * reported "the subdomain … was removed" having removed nothing, on the one
     * screen where every other line describes something irreversible that
     * really did happen.
     */
    public function test_it_does_not_claim_to_remove_a_domain_it_never_points(): void
    {
        $customer = $this->shop();

        app(DomainMaker::class)->automatic = false;
        app(DnsMaker::class)->automatic = false;

        $result = $this->remover()->remove($customer);

        $this->assertNotContains("the subdomain {$customer->host} was removed", $result['done']);

        $said = implode(' | ', $result['done']);

        $this->assertStringContainsString('subdomain '.$customer->host.' is yours to remove', $said);
        $this->assertStringContainsString('DNS record is yours to remove', $said);
    }

    /**
     * What the removal could not finish has to outlive the flash message.
     *
     * This is the shape of the real failure: everything irreversible happened,
     * and a subdomain was left pointing at a folder that no longer exists. Read
     * on the redirect and then lost — the page for the shop is where anybody
     * goes back to ask what happened.
     */
    public function test_what_was_left_behind_is_still_on_the_page_afterwards(): void
    {
        $customer = $this->shop();

        app(DomainMaker::class)->leaves = ['the domain [bazaar.soranstore.com], which cPanel still lists'];

        $this->delete(route('customers.remove', $customer))
            ->assertSessionHas('warning', fn (string $said) => str_contains($said, 'left behind'));

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('These were left behind')
            ->assertSee('which cPanel still lists');
    }

    // --------------------------------------------------------- what survives

    public function test_the_customer_the_licences_and_the_money_all_stay_on_record(): void
    {
        $customer = $this->shop();

        $licence = Licence::factory()->for($customer)->create();
        $payment = Payment::factory()->for($customer)->create();

        $this->remover()->remove($customer);

        $gone = Customer::withTrashed()->find($customer->id);

        $this->assertNotNull($gone, 'the customer row was destroyed with the shop');
        $this->assertTrue($gone->trashed());
        $this->assertSame(Customer::ENDED, $gone->status);

        $this->assertDatabaseHas('licences', ['id' => $licence->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);

        // And out of every list, without another query saying so.
        $this->assertSame(0, Customer::count());
    }

    /**
     * Section 1: anything reaching into a customer's install leaves a record
     * with a name on it. A removal is the most important record there is, and
     * it is the one the soft delete would have blanked.
     */
    public function test_the_removal_is_logged_and_still_knows_whose_shop_it_was(): void
    {
        $customer = $this->shop();

        $result = $this->remover()->remove($customer, 'they closed the shop');

        $action = Action::where('action', 'shop.removed')->latest('id')->firstOrFail();

        $this->assertSame('they closed the shop', $action->detail['why']);
        $this->assertSame($result['backup'], $action->detail['backup']);
        $this->assertSame('Soran', $action->user->name);

        $this->assertNotNull($action->customer, 'the log lost the shop’s name the moment it was removed');
        $this->assertSame('Bazaar', $action->customer->name);
    }

    /** Removing is what frees the name, so the same shop can be rebuilt. */
    public function test_the_host_can_be_used_again_afterwards(): void
    {
        $customer = $this->shop();

        $this->remover()->remove($customer);

        $this->assertFalse(
            Customer::where('host', 'bazaar.soranstore.com')->exists(),
            'the host is still held by a shop that no longer exists',
        );
    }

    // ------------------------------------------------------------ the screen

    public function test_the_button_is_disabled_with_the_reason_on_it_while_a_shop_trades(): void
    {
        $customer = $this->shop(['status' => Customer::ACTIVE]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Suspend it first', escape: false)
            ->assertDontSee('Remove it for ever');
    }

    public function test_removing_from_the_screen_needs_the_host_typed_and_says_where_the_backup_went(): void
    {
        $customer = $this->shop();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Remove it for ever')
            ->assertSee('data-confirm-word="bazaar.soranstore.com"', escape: false);

        $this->delete(route('customers.remove', $customer), ['why' => 'closed'])
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success', fn (string $said) => str_contains($said, 'has been removed')
                && str_contains($said, $this->root.'/removed-shops/'));
    }

    /** The page stays readable at the same address, with the controls gone. */
    public function test_a_removed_shops_page_is_still_there_and_has_no_danger_zone(): void
    {
        $customer = $this->shop();

        $this->remover()->remove($customer);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('This shop was removed')
            ->assertDontSee('Danger zone')
            ->assertDontSee('Remove it for ever');
    }

    /** A shop already removed cannot be removed a second time. */
    public function test_a_removed_shop_cannot_be_removed_again(): void
    {
        $customer = $this->shop();

        $this->remover()->remove($customer);

        $this->delete(route('customers.remove', $customer))->assertNotFound();
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
