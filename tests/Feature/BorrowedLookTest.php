<?php

namespace Tests\Feature;

use App\Services\BorrowedLook;
use RuntimeException;
use Tests\TestCase;

/**
 * The look the panel borrows from the shop system — PANEL_DOC Section 10.
 *
 * The case these exist for is the one that happened: the shop system's build
 * was not where it was expected, the old copy had already been deleted, and the
 * live panel served unstyled HTML with nothing on screen to say why. So the
 * assertions that matter most here are about what is still there after a
 * failure, not about what a success copies.
 */
class BorrowedLookTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/look-'.bin2hex(random_bytes(6));

        mkdir($this->root.'/shop-system/public', 0777, true);
        mkdir($this->root.'/public_html', 0777, true);
        mkdir($this->root.'/panel-public', 0777, true);

        // The panel's own public/ — where `public_path()` will point.
        $this->app->usePublicPath($this->root.'/panel-public');

        config([
            'panel.shops.shared_artisan' => $this->root.'/shop-system/artisan',
            'panel.shops.public_root' => $this->root.'/public_html',
        ]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root ?? '');

        parent::tearDown();
    }

    public function test_it_finds_the_build_beside_the_shared_artisan(): void
    {
        $this->assertSame(
            $this->root.'/shop-system/public/build',
            app(BorrowedLook::class)->source(),
        );
    }

    public function test_a_missing_source_is_a_problem_and_nothing_is_touched(): void
    {
        $this->wearing('old');

        $look = app(BorrowedLook::class);

        $this->assertNotSame([], $look->problems());

        $this->artisan('panel:assets')
            ->expectsOutputToContain('There is no [')
            ->assertExitCode(1);

        $this->assertSame('old', $this->worn());
    }

    /**
     * The real failure, as a test.
     *
     * A folder called `build` with nothing finished in it passes `is_dir` and
     * would have satisfied the shell version — which had already deleted the
     * panel's own copy by this point.
     */
    public function test_a_source_that_is_not_a_finished_build_leaves_the_old_one_alone(): void
    {
        $this->wearing('old');

        mkdir($this->root.'/shop-system/public/build', 0777, true);

        $this->artisan('panel:assets')
            ->expectsOutputToContain('has no manifest.json')
            ->expectsOutputToContain('has no stylesheet')
            ->assertExitCode(1);

        $this->assertSame('old', $this->worn(), 'The panel lost its look to a source that was never usable.');
    }

    /**
     * The same guard, reached the way `Updater` reaches it.
     *
     * The tests above drive the command, and the command checks the source
     * itself before calling any of this — so they prove the command is careful
     * and say nothing about the service. Updating the shop system calls
     * `refresh()` directly, with no command in front of it, and that is the path
     * that must not be able to delete a working build.
     */
    public function test_refresh_refuses_an_unusable_source_without_touching_anything(): void
    {
        $this->wearing('old');

        mkdir($this->root.'/shop-system/public/build', 0777, true);

        try {
            app(BorrowedLook::class)->refresh();
            $this->fail('refresh() accepted a folder that is not a finished build.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->assertSame('old', $this->worn(), 'refresh() deleted a working build for a source it could not use.');
    }

    public function test_refresh_leaves_the_old_build_when_the_source_disappears_entirely(): void
    {
        $this->wearing('old');

        try {
            app(BorrowedLook::class)->refresh();
            $this->fail('refresh() accepted a source that is not there.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('There is no [', $e->getMessage());
        }

        $this->assertSame('old', $this->worn());
    }

    public function test_it_replaces_the_panels_own_copy(): void
    {
        $this->wearing('old');
        $this->shopSystemBuilt('new');

        $this->artisan('panel:assets')->assertExitCode(0);

        $this->assertSame('new', $this->worn());
    }

    public function test_it_also_refreshes_the_folder_the_domain_serves(): void
    {
        $this->wearing('old');
        $this->shopSystemBuilt('new');

        // What `panel:public` writes: an index.php naming this panel's own base
        // path absolutely, because `..` from inside public_html is public_html.
        $served = $this->root.'/public_html/panel';
        mkdir($served.'/build/assets', 0777, true);
        file_put_contents($served.'/index.php', "<?php require '".base_path()."/vendor/autoload.php';");
        file_put_contents($served.'/build/manifest.json', '"old"');

        // A shop's own folder, which names its own code and must be left alone.
        $shop = $this->root.'/public_html/bazaar';
        mkdir($shop.'/build', 0777, true);
        file_put_contents($shop.'/index.php', "<?php require '/home/soransto/shops/bazaar/vendor/autoload.php';");
        file_put_contents($shop.'/build/manifest.json', '"the shop\'s own"');

        $this->artisan('panel:assets')->assertExitCode(0);

        $this->assertSame('"new"', file_get_contents($served.'/build/manifest.json'));
        $this->assertSame('"the shop\'s own"', file_get_contents($shop.'/build/manifest.json'),
            'A shop\'s public folder was rewritten by a command that is only about the panel.');
    }

    /**
     * Soran's panel on 11 September, as a test.
     *
     * The panel's own `public/build` had been refreshed by hand and the folder
     * the domain serves had not — `panel:public` refuses a folder that already
     * has a panel in it unless it is given `--force`, so the second copy simply
     * never happened. Nothing threw. The manifest named the new hashes, the
     * browser asked the old folder for them, every one 404'd, and the panel
     * served its real content as unstyled HTML with nothing on any screen
     * saying why.
     */
    public function test_it_names_a_served_folder_holding_an_older_copy(): void
    {
        // The panel asks for the new hash.
        mkdir(public_path('build/assets'), 0777, true);
        file_put_contents(public_path('build/manifest.json'), json_encode([
            'resources/scss/app.scss' => ['file' => 'assets/app-NEW.css'],
        ]));
        file_put_contents(public_path('build/assets/app-NEW.css'), 'body{}');

        // The folder the domain serves still holds the old one.
        $served = $this->root.'/public_html/panel';
        mkdir($served.'/build/assets', 0777, true);
        file_put_contents($served.'/index.php', "<?php require '".base_path()."/vendor/autoload.php';");
        file_put_contents($served.'/build/manifest.json', '{}');
        file_put_contents($served.'/build/assets/app-OLD.css', 'body{}');

        $look = app(BorrowedLook::class);

        $this->assertTrue($look->inPlace(), 'The panel has a manifest, so nothing throws — that is the trap.');
        $this->assertSame([$served], $look->stale());

        // And taking the look again writes both copies, which is the fix.
        $this->shopSystemBuilt('new');
        $look->refresh();

        $this->assertSame([], $look->stale());
    }

    public function test_a_served_folder_holding_the_same_copy_is_not_stale(): void
    {
        $this->wearing('old');
        $this->shopSystemBuilt('new');

        $served = $this->root.'/public_html/panel';
        mkdir($served.'/build', 0777, true);
        file_put_contents($served.'/index.php', "<?php require '".base_path()."/vendor/autoload.php';");
        file_put_contents($served.'/build/manifest.json', '{}');

        app(BorrowedLook::class)->refresh();

        $this->assertSame([], app(BorrowedLook::class)->stale());
    }

    public function test_it_leaves_nothing_half_written_behind(): void
    {
        $this->wearing('old');
        $this->shopSystemBuilt('new');

        $this->artisan('panel:assets')->assertExitCode(0);

        $this->assertDirectoryDoesNotExist(public_path('build.incoming'));
        $this->assertDirectoryDoesNotExist(public_path('build.previous'));
    }

    public function test_it_says_the_panel_has_no_look_at_all(): void
    {
        $look = app(BorrowedLook::class);

        $this->assertFalse($look->inPlace());

        $this->wearing('old');

        $this->assertTrue($look->inPlace());
    }

    /** Give the panel a build to be wearing. */
    private function wearing(string $mark): void
    {
        mkdir(public_path('build/assets'), 0777, true);
        file_put_contents(public_path('build/manifest.json'), json_encode($mark));
    }

    /** What the panel is wearing now. */
    private function worn(): string
    {
        return json_decode((string) @file_get_contents(public_path('build/manifest.json')), true) ?? '';
    }

    /** Give the shop system a finished build to be copied. */
    private function shopSystemBuilt(string $mark): void
    {
        $build = $this->root.'/shop-system/public/build';

        mkdir($build.'/assets', 0777, true);
        file_put_contents($build.'/manifest.json', json_encode($mark));
        file_put_contents($build.'/assets/app-abc123.css', 'body{}');
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;

            is_dir($full) && ! is_link($full) ? $this->rmrf($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
