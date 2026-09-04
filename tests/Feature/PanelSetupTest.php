<?php

namespace Tests\Feature;

use App\Console\Commands\PanelSetup;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use Tests\TestCase;

/**
 * `panel:setup` — the .env filled in by being asked.
 *
 * Written after a real deploy, where every failure was a value typed into a
 * file rather than anything conceptual: a template database name that looked
 * plausible, a prefix guessed at instead of read off the cPanel page, and a
 * generated password with characters that need quoting.
 *
 * So these tests are mostly about the writing: that a value lands where dotenv
 * will actually read it, and that a password full of awkward characters
 * survives the trip.
 */
class PanelSetupTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = base_path('.env');
        Prompt::fake([]);
    }

    /** The real .env is never touched; each test works on a copy. */
    private function envFile(string $contents): string
    {
        $path = sys_get_temp_dir().'/panel-env-'.bin2hex(random_bytes(6));
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Reflection rather than running the command, because the write is the part
     * worth pinning down and the rest of the command is six questions.
     *
     * @param  array<string, string>  $set
     */
    private function write(string $path, array $set): void
    {
        $command = app(PanelSetup::class);

        $method = new \ReflectionMethod($command, 'write');
        $method->invoke($command, $path, $set);
    }

    /**
     * ⚠️ Replaced in place, never appended alongside.
     *
     * Laravel's dotenv is immutable: the FIRST definition of a key wins. A
     * duplicate appended at the end is read past in silence, so the setting
     * looks saved, `panel:check` still says it is wrong, and there is nothing
     * on screen to explain the disagreement.
     */
    public function test_a_key_that_is_already_there_is_replaced_and_not_duplicated(): void
    {
        $path = $this->envFile("APP_NAME=Panel\nDB_DATABASE=soran_panel\nDB_USERNAME=\n");

        $this->write($path, ['DB_DATABASE' => 'soransto_panel']);

        $written = (string) file_get_contents($path);

        $this->assertSame(1, substr_count($written, 'DB_DATABASE='));
        $this->assertStringContainsString('DB_DATABASE=soransto_panel', $written);
        $this->assertStringNotContainsString('DB_DATABASE=soran_panel', $written);

        // And nothing else was disturbed.
        $this->assertStringContainsString('APP_NAME=Panel', $written);
    }

    public function test_a_key_that_is_missing_is_added(): void
    {
        $path = $this->envFile("APP_NAME=Panel\n");

        $this->write($path, ['PANEL_CPANEL_PREFIX' => 'soransto']);

        $this->assertStringContainsString('PANEL_CPANEL_PREFIX=soransto', (string) file_get_contents($path));
    }

    /**
     * ⚠️ The one that has already cost this project a green CI run.
     *
     * A double-quoted value has its escape sequences processed, so `\s` or `\p`
     * makes dotenv reject THE WHOLE FILE — every setting lost at once, not just
     * the password. Single quotes, and only where they are needed.
     */
    public function test_a_password_full_of_awkward_characters_survives(): void
    {
        $awkward = [
            'pa\\ss#w0rd$x',           // the backslash that rejects the whole file
            'has "double" quotes',
            "has 'an apostrophe'",     // single quotes cannot hold this one
            'spaces and =equals=',
            'both \\ and " and \'',
            '100% $PATH ${notavar',
        ];

        foreach ($awkward as $value) {
            $path = $this->envFile("DB_PASSWORD=\n");

            $this->write($path, ['DB_PASSWORD' => $value]);

            // Read it back exactly the way the framework will.
            $read = Dotenv::parse((string) file_get_contents($path));

            $this->assertSame($value, $read['DB_PASSWORD'], "[{$value}] did not survive the .env");
        }
    }

    /**
     * The one shape an .env cannot hold: an apostrophe rules out single quotes,
     * and `${` interpolates inside double ones with nothing to escape it with.
     * Said out loud rather than written and silently misread later.
     */
    public function test_a_value_that_cannot_be_written_is_refused_rather_than_mangled(): void
    {
        $path = $this->envFile("DB_PASSWORD=\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot hold both');

        $this->write($path, ['DB_PASSWORD' => 'it\'s ${HOME}']);
    }

    public function test_an_ordinary_value_is_left_unquoted(): void
    {
        $path = $this->envFile("APP_URL=\n");

        $this->write($path, ['APP_URL' => 'https://panel.soranstore.com']);

        $this->assertStringContainsString(
            'APP_URL=https://panel.soranstore.com',
            (string) file_get_contents($path),
        );
    }

    /** A half-written .env is a panel nobody can sign in to. */
    public function test_the_file_keeps_its_permissions_and_no_scratch_file_is_left(): void
    {
        $path = $this->envFile("DB_PASSWORD=\n");
        chmod($path, 0600);

        $this->write($path, ['DB_PASSWORD' => 'something']);

        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame([], glob(dirname($path).'/'.basename($path).'.setup-*') ?: []);
    }

    /** Every key it writes must exist in the template, or it is writing a typo. */
    public function test_everything_it_sets_is_a_key_the_template_knows(): void
    {
        $template = (string) File::get(base_path('.env.example'));

        $keys = [
            'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'PANEL_CPANEL_PREFIX',
            'PANEL_ADMIN_NAME', 'PANEL_ADMIN_EMAIL', 'PANEL_ADMIN_PASSWORD',
            'APP_URL',
        ];

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($key, '/').'=/m',
                $template,
                "panel:setup writes [{$key}], which .env.example does not have — one of the two is wrong",
            );
        }
    }
}
