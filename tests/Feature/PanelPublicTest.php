<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The panel's public folder — PANEL_DOC Section 4.
 *
 * "Document roots cannot leave public_html. Tested: cPanel silently created its
 * own folder inside public_html and ignored the path typed in." So the panel
 * cannot point its domain at its own `public/`. The six files the web is
 * allowed to see are copied to a folder inside `public_html`, and everything
 * else — `.env` most of all — stays outside where no URL reaches it.
 *
 * The whole risk is in one file. Laravel's `public/index.php` reaches its
 * application through `__DIR__.'/../vendor/autoload.php'`, and once the folder
 * has moved, `..` is `public_html`. The generated one names the panel's base
 * absolutely, and this holds that it does.
 */
class PanelPublicTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/panel-public-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function target(): string
    {
        return $this->root.'/public_html/panel';
    }

    public function test_it_writes_the_files_the_web_is_allowed_to_see(): void
    {
        $this->artisan('panel:public', ['path' => $this->target()])->assertSuccessful();

        $this->assertFileExists($this->target().'/index.php');
        $this->assertFileExists($this->target().'/.htaccess');

        // And nothing else of the panel.
        $written = array_diff((array) scandir($this->target()), ['.', '..']);

        $this->assertEmpty(
            array_diff($written, ['index.php', '.htaccess', 'build', 'favicon.ico', 'robots.txt']),
            'it wrote something into the document root that is not meant to be public',
        );
    }

    /** The one thing that has to change: `..` from public_html is not the panel. */
    public function test_the_index_reaches_the_panel_by_an_absolute_path(): void
    {
        $this->artisan('panel:public', ['path' => $this->target()]);

        $index = file_get_contents($this->target().'/index.php');

        $this->assertStringContainsString(base_path().'/vendor/autoload.php', $index);
        $this->assertStringContainsString(base_path().'/bootstrap/app.php', $index);
        $this->assertStringNotContainsString("__DIR__.'/../", $index, 'a relative path would reach public_html');
    }

    /** It has to be PHP that parses, or the panel is a 500 with no explanation. */
    public function test_the_index_it_writes_is_valid_php(): void
    {
        $this->artisan('panel:public', ['path' => $this->target()]);

        $lint = new Process([PHP_BINARY, '-l', $this->target().'/index.php']);
        $lint->run();

        $this->assertTrue($lint->isSuccessful(), $lint->getOutput());
    }

    public function test_it_brings_the_borrowed_stylesheet_with_it(): void
    {
        $this->artisan('panel:public', ['path' => $this->target()]);

        if (is_dir(public_path('build'))) {
            $this->assertFileExists($this->target().'/build/manifest.json');
        } else {
            $this->markTestSkipped('public/build is not here; it is copied in at deploy time.');
        }
    }

    /** A folder with somebody's website in it is not a place to scatter an index.php. */
    public function test_it_refuses_a_folder_with_something_else_in_it(): void
    {
        File::ensureDirectoryExists($this->target());
        File::put($this->target().'/somebody-elses-site.html', 'hello');

        $this->artisan('panel:public', ['path' => $this->target()])->assertFailed();

        $this->assertFileDoesNotExist($this->target().'/index.php');
    }

    public function test_it_refuses_to_write_over_itself_without_being_told(): void
    {
        $this->artisan('panel:public', ['path' => $this->target()])->assertSuccessful();
        $this->artisan('panel:public', ['path' => $this->target()])->assertFailed();
    }

    public function test_force_writes_over_a_panel_that_is_already_there(): void
    {
        $this->artisan('panel:public', ['path' => $this->target()])->assertSuccessful();

        File::put($this->target().'/index.php', 'stale');

        $this->artisan('panel:public', ['path' => $this->target(), '--force' => true])->assertSuccessful();

        $this->assertStringContainsString(base_path(), file_get_contents($this->target().'/index.php'));
    }

    /** Pointed at the panel's own public/, it would be writing over itself. */
    public function test_it_refuses_the_panels_own_public_folder(): void
    {
        $this->artisan('panel:public', ['path' => public_path()])->assertFailed();
    }
}
