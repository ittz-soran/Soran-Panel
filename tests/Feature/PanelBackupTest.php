<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\User;
use App\Services\PanelBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The panel's own backup — PANEL_DOC Section 13.
 *
 * Every other backup here is a shop's. This is the one nobody else takes: the
 * customer list, every licence ever issued and the whole payment record. Losing
 * a shop's database loses one shop; losing this one loses who your customers
 * are, and no shop on the server can tell you any of it back.
 *
 * So the test that matters most is not that a file appears. It is
 * `test_a_backup_can_actually_be_put_back`, which drops a whole database and
 * restores it — because Section 13 borrowed the shop system's sentence and the
 * sentence is the point: **an untested backup is not a backup.**
 */
class PanelBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/panel-backup-'.bin2hex(random_bytes(6));

        config([
            'panel.backups.path' => $this->root.'/kept',
            'panel.backups.offsite' => '',
            'panel.backups.keep_daily' => 30,
            'panel.backups.keep_monthly' => 12,
            'panel.backups.mysqldump' => '',
            'panel.backups.mysql' => '',
            'panel.backups.gzip' => '',
        ]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root ?? '');

        parent::tearDown();
    }

    /**
     * This dumps a real database with the real tool. Both are reasons a run can
     * legitimately not exercise it, and neither is a failure of the panel.
     */
    private function needsARealDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The panel’s backup dumps MySQL; this run is on SQLite.');
        }

        foreach (['mysqldump', 'mariadb-dump'] as $tool) {
            $process = new Process([$tool, '--version']);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }
        }

        $this->markTestSkipped('mysqldump is not on this machine, so there is nothing to dump with.');
    }

    private function backups(): PanelBackup
    {
        return app(PanelBackup::class);
    }

    // ------------------------------------------------------------ where it goes

    /**
     * ⚠️ Beside the panel, never inside it.
     *
     * `~/panel` is a git checkout: it gets pulled, and one day it gets deleted
     * and recloned. A backup of the panel's own database that lives inside it
     * is one that goes with it, on exactly the day it was for.
     */
    public function test_the_default_folder_is_outside_the_panels_own_checkout(): void
    {
        config(['panel.backups.path' => '']);

        $where = $this->backups()->where();

        $this->assertStringStartsNotWith(rtrim(base_path(), '/').'/', rtrim($where, '/').'/');
        $this->assertSame(dirname(base_path()).'/panel-backups', $where);
    }

    public function test_the_setting_wins_when_it_is_given(): void
    {
        $this->assertSame($this->root.'/kept', $this->backups()->where());
    }

    // ---------------------------------------------------------------- the dump

    public function test_it_writes_a_gzipped_dump_of_the_panels_own_tables(): void
    {
        $this->needsARealDatabase();

        $result = $this->backups()->run();

        $this->assertFileExists($result['path']);
        $this->assertStringEndsWith('.sql.gz', $result['path']);
        $this->assertGreaterThan(0, $result['bytes']);

        $sql = (string) $this->readGzip($result['path']);

        // Every table Section 5 names. A dump missing one of these restores a
        // panel that cannot say who its customers are.
        foreach (['customers', 'licences', 'payments', 'health_checks', 'actions', 'users'] as $table) {
            $this->assertStringContainsString("CREATE TABLE `{$table}`", $sql, "[{$table}] is not in the dump");
        }
    }

    /**
     * The dump is measured on what mysqldump WROTE, not on the file's size.
     *
     * gzip writes a header and a footer whatever you give it, so an empty dump
     * lands as a perfectly valid twenty-byte archive. The first version checked
     * `filesize() === 0` and could therefore never fire — found by writing this.
     */
    public function test_a_dump_that_produces_nothing_is_thrown_away_rather_than_kept(): void
    {
        $this->needsARealDatabase();

        // Exits zero, writes nothing. The failure this must catch.
        config(['panel.backups.mysqldump' => '/bin/true']);

        try {
            $this->backups()->run();
            $this->fail('an empty backup was kept');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('came out empty', $e->getMessage());
        }

        $this->assertSame([], $this->backups()->copies('daily'), 'the empty file was left on disk');
    }

    /** A nightly run nobody watches must leave evidence when it fails. */
    public function test_a_failed_backup_is_written_into_the_log(): void
    {
        config(['panel.backups.mysqldump' => '/nowhere/mysqldump']);

        try {
            $this->backups()->run();
            $this->fail('a backup with no mysqldump reported success');
        } catch (RuntimeException) {
            // The message is the tool's; what matters is the row below.
        }

        $this->assertDatabaseHas('actions', ['action' => 'panel.backup_failed']);
    }

    // --------------------------------------------------------------- what it keeps

    public function test_the_first_backup_of_a_month_is_also_kept_as_the_months_copy(): void
    {
        $this->needsARealDatabase();

        $this->backups()->run();

        $monthly = $this->backups()->copies('monthly');

        $this->assertCount(1, $monthly);
        $this->assertSame('panel-'.now()->format('Y-m').'.sql.gz', $monthly[0]->getFilename());
    }

    public function test_old_nightly_copies_are_pruned_and_the_newest_are_not(): void
    {
        config(['panel.backups.keep_daily' => 3]);

        $folder = $this->root.'/kept/daily';
        mkdir($folder, 0750, true);

        foreach (range(1, 6) as $day) {
            $path = $folder.'/panel-2026-09-0'.$day.'-000000.sql.gz';
            file_put_contents($path, 'x');
            touch($path, now()->subDays(10 - $day)->getTimestamp());
        }

        $keep = new \ReflectionMethod(PanelBackup::class, 'prune');
        $keep->invoke($this->backups(), 'daily', 3);

        $left = array_map(fn ($file) => $file->getFilename(), $this->backups()->copies('daily'));

        $this->assertCount(3, $left);
        $this->assertSame([
            'panel-2026-09-06-000000.sql.gz',
            'panel-2026-09-05-000000.sql.gz',
            'panel-2026-09-04-000000.sql.gz',
        ], $left, 'pruning kept the wrong end of the list');
    }

    // ------------------------------------------------------- off the machine

    /**
     * Section 13's whole point. A backup beside the database survives a
     * mistake and not a dead disk, so having no second copy is said out loud
     * on every single run rather than being a setting nobody reads.
     */
    public function test_no_off_machine_copy_is_a_warning_on_every_run(): void
    {
        $this->needsARealDatabase();

        $result = $this->backups()->run();

        $this->assertNotSame([], $result['warnings']);
        $this->assertStringContainsString('same disk', $result['warnings'][0]);
        $this->assertNull($result['offsite']);
    }

    public function test_a_second_copy_goes_where_it_is_told(): void
    {
        $this->needsARealDatabase();

        config(['panel.backups.offsite' => $this->root.'/elsewhere']);

        $result = $this->backups()->run();

        $this->assertSame([], $result['warnings']);
        $this->assertNotNull($result['offsite']);
        $this->assertFileExists($result['offsite']);
        $this->assertSame(filesize($result['path']), filesize($result['offsite']));
    }

    /** An unreachable second folder must not lose the copy that did work. */
    public function test_an_off_machine_folder_that_cannot_be_reached_still_leaves_the_local_copy(): void
    {
        $this->needsARealDatabase();

        config(['panel.backups.offsite' => '/proc/nowhere/backups']);

        $result = $this->backups()->run();

        $this->assertFileExists($result['path']);
        $this->assertNull($result['offsite']);
        $this->assertStringContainsString('could not be reached', $result['warnings'][0]);
    }

    // ------------------------------------------------------------- how old it is

    public function test_never_having_run_is_stale_and_so_is_three_days_ago(): void
    {
        $this->assertNull($this->backups()->lastRunAt());
        $this->assertTrue($this->backups()->isStale());

        $folder = $this->root.'/kept/daily';
        mkdir($folder, 0750, true);

        $path = $folder.'/panel-old.sql.gz';
        file_put_contents($path, 'x');
        touch($path, now()->subDays(3)->getTimestamp());

        $this->assertTrue($this->backups()->isStale());

        touch($path, now()->subHours(6)->getTimestamp());

        $this->assertFalse($this->backups()->isStale(), 'this morning’s backup was called stale');
    }

    // ----------------------------------------------------------------- the drill

    /**
     * ⚠️ **The test this file exists for.**
     *
     * A whole database is dropped and put back from the file. Nothing else here
     * proves a backup is a backup: a `.sql.gz` of the right size that no one has
     * ever restored is a file, and the day it turns out not to restore is the
     * one day it was needed.
     *
     * Done on a scratch database of its own — the suite's own database is not
     * risked to prove this, and `TestCase` would refuse it anyway.
     */
    public function test_a_backup_can_actually_be_put_back(): void
    {
        $this->needsARealDatabase();

        $scratch = 'panel_drill_'.bin2hex(random_bytes(4)).'_test';
        $was = (string) config('database.default');

        try {
            DB::statement("create database `{$scratch}` character set utf8mb4 collate utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            $this->markTestSkipped('This connection may not CREATE DATABASE, so the drill cannot be run here.');
        }

        config([
            'database.connections.drill' => [
                ...config("database.connections.{$was}"),
                'database' => $scratch,
            ],
            'database.default' => 'drill',
        ]);

        try {
            Artisan::call('migrate', ['--force' => true]);

            DB::connection('drill')->table('customers')->insert([
                'name' => 'Bazaar', 'host' => 'bazaar.soranstore.com',
                'shop_home' => '/h/s/b', 'public_path' => '/h/p/b',
                'database_name' => 'b_shop', 'status' => 'active',
                'monthly_fee' => 50000, 'language' => 'ckb',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $result = $this->backups()->run();

            // Everything gone — not a dropped table, the whole database.
            DB::purge('drill');
            DB::connection($was)->statement("drop database `{$scratch}`");
            DB::connection($was)->statement("create database `{$scratch}` character set utf8mb4 collate utf8mb4_unicode_ci");

            $this->assertSame(
                0,
                (int) DB::connection('drill')->selectOne(
                    'select count(*) as n from information_schema.tables where table_schema = ?', [$scratch],
                )->n,
                'the drill did not actually destroy anything, so it proves nothing',
            );

            $this->backups()->restore($result['path']);

            DB::purge('drill');

            $customer = DB::connection('drill')->table('customers')->first();

            $this->assertNotNull($customer, 'the restore brought nothing back');
            $this->assertSame('Bazaar', $customer->name);
            $this->assertSame(50000, (int) $customer->monthly_fee);
        } finally {
            /*
             * Put the default connection back before anything else runs.
             * RefreshDatabase resolves the connection to roll back by asking for
             * the default AGAIN in tearDown — leave it pointing at the scratch
             * database and the rollback happens on the wrong one.
             */
            config(['database.default' => $was]);
            DB::purge('drill');
            DB::connection($was)->statement("drop database if exists `{$scratch}`");
        }
    }

    // ----------------------------------------------------------------- the screen

    public function test_the_health_screen_says_when_it_was_last_backed_up(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('health.index'))
            ->assertOk()
            ->assertSee('The panel’s own backup', escape: false)
            ->assertSee('has never been backed up', escape: false);
    }

    /**
     * The other half of the screen, and the half with the formatting in it: a
     * size, a count and a download link, all reading files rather than a
     * database. Kept off MySQL so it runs everywhere the views do.
     */
    public function test_the_screen_shows_the_newest_backup_and_offers_it_for_download(): void
    {
        $this->actingAs(User::factory()->create());

        $folder = $this->root.'/kept/daily';
        mkdir($folder, 0750, true);
        file_put_contents($folder.'/panel-2026-09-06-023000.sql.gz', str_repeat('x', 4096));

        $this->get(route('health.index'))
            ->assertOk()
            ->assertDontSee('has never been backed up', escape: false)
            ->assertSee('4.0 KB')
            ->assertSee('Download the newest')
            ->assertSee('panel-2026-09-06-023000.sql.gz')
            ->assertSee('Nothing is copied off this machine', escape: false);
    }

    public function test_backing_up_from_the_screen_works_and_is_written_down(): void
    {
        $this->needsARealDatabase();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->post(route('health.backup'))
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $said) => str_contains($said, 'The panel is backed up'));

        $action = Action::where('action', 'panel.backed_up')->firstOrFail();

        $this->assertSame('Soran', $action->user->name);
        $this->assertGreaterThan(0, $action->detail['bytes']);
    }

    public function test_a_backup_can_be_downloaded_and_nothing_else_can(): void
    {
        $this->needsARealDatabase();

        $this->actingAs(User::factory()->create());

        $result = $this->backups()->run();
        $name = basename($result['path']);

        $this->get(route('health.backup.download', ['kind' => 'daily', 'name' => $name]))
            ->assertOk()
            ->assertDownload($name);

        // The panel's .env is the file this route would be worth attacking for.
        $this->get('/health/backup/daily/'.rawurlencode('../../../.env'))->assertNotFound();
        $this->get(route('health.backup.download', ['kind' => 'daily', 'name' => 'no-such.sql.gz']))
            ->assertNotFound();
        $this->get(route('health.backup.download', ['kind' => 'weekly', 'name' => $name]))
            ->assertNotFound();
    }

    public function test_the_backup_screen_is_behind_the_sign_in(): void
    {
        $this->post(route('health.backup'))->assertRedirect(route('login'));
        $this->get(route('health.backup.download', ['kind' => 'daily', 'name' => 'x.sql.gz']))
            ->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ helpers

    private function readGzip(string $path): string
    {
        $handle = gzopen($path, 'rb');
        $sql = '';

        while (! gzeof($handle)) {
            $sql .= gzread($handle, 65536);
        }

        gzclose($handle);

        return $sql;
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
