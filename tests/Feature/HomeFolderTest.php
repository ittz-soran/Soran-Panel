<?php

namespace Tests\Feature;

use App\Support\HomeFolder;
use Tests\TestCase;

/**
 * Finding the account's home folder.
 *
 * This exists because of a live failure: creating a shop from the panel's own
 * screens stopped with "the home folder is not known", while the same thing
 * over SSH worked every time. `$HOME` is set by a login shell and is usually
 * empty under PHP-FPM, so the panel could only do in a terminal what it is
 * there to do in a browser.
 */
class HomeFolderTest extends TestCase
{
    private string|false $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = getenv('HOME');
    }

    protected function tearDown(): void
    {
        is_string($this->home) ? putenv('HOME='.$this->home) : putenv('HOME');

        parent::tearDown();
    }

    public function test_the_setting_wins_because_somebody_set_it_on_purpose(): void
    {
        config(['panel.cpanel.home' => '/home/soransto']);
        putenv('HOME=/home/somebody-else');

        $this->assertSame('/home/soransto', HomeFolder::find());
    }

    public function test_the_environment_answers_when_nothing_was_set(): void
    {
        config(['panel.cpanel.home' => '']);
        putenv('HOME=/home/soransto');

        $this->assertSame('/home/soransto', HomeFolder::find());
    }

    /** A trailing slash would make the document root come out with a leading one. */
    public function test_the_trailing_slash_is_dropped(): void
    {
        config(['panel.cpanel.home' => '/home/soransto/']);

        $this->assertSame('/home/soransto', HomeFolder::find());
    }

    /**
     * The one that matters. Neither the setting nor `$HOME` — a web request on
     * a stock cPanel account — and it still has to come up with an answer.
     */
    public function test_a_web_request_with_no_home_in_its_environment_still_finds_one(): void
    {
        if (! function_exists('posix_getpwuid') || ! function_exists('posix_getuid')) {
            $this->markTestSkipped('the posix extension is not here to ask');
        }

        config(['panel.cpanel.home' => '']);
        putenv('HOME');

        $this->assertSame(
            rtrim((string) posix_getpwuid(posix_getuid())['dir'], '/'),
            HomeFolder::find(),
            'the panel fell back to nothing, so every shop made from a browser would be refused',
        );
    }
}
