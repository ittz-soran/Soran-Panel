<?php

namespace Tests\Feature;

use App\Contracts\ShopWriter;
use App\Models\Customer;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Changing a shop's `.env` — PANEL_DOC Sections 6 and 7.
 *
 * A shop's `.env` holds its database password and its APP_KEY. A half-written
 * one is not a shop with a wrong setting, it is a shop that does not start —
 * and if APP_KEY is what got lost, its sessions and every encrypted column are
 * unreadable for ever. So what is held here is mostly about the file that is
 * NOT written: on any failure the original must still be there, whole.
 */
class ShopWriterTest extends TestCase
{
    use RefreshDatabase;

    private string $home;

    private const ENV = <<<'ENV'
    APP_NAME="Bazaar Computer"
    APP_KEY=base64:xVoWIzQ0Zk8N0k8fJm0kZ3v2mQ0kZ3v2mQ0kZ3v2mQ0=
    APP_ENV=production

    # The shop's own database
    DB_DATABASE=bazaar_shop
    DB_PASSWORD=a-real-password

    LICENCE_PUBLIC_KEY=
    LICENCE_KEY=
    STORAGE_LIMIT_MB=1024
    ENV;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir().'/shop-write-'.bin2hex(random_bytes(6));
        mkdir($this->home, 0755, true);
        file_put_contents($this->home.'/.env', self::ENV);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->home);

        parent::tearDown();
    }

    private function shop(): Customer
    {
        return Customer::factory()->create(['shop_home' => $this->home]);
    }

    private function env(): string
    {
        return (string) file_get_contents($this->home.'/.env');
    }

    private function writer(): ShopWriter
    {
        return app(ShopWriter::class);
    }

    public function test_it_replaces_a_key_in_place(): void
    {
        $this->writer()->putEnv($this->shop(), ['STORAGE_LIMIT_MB' => '4096']);

        $this->assertStringContainsString('STORAGE_LIMIT_MB=4096', $this->env());
        $this->assertStringNotContainsString('STORAGE_LIMIT_MB=1024', $this->env());
    }

    /** A shop's .env is a file somebody may have to read at three in the morning. */
    public function test_it_keeps_the_comments_the_blank_lines_and_the_order(): void
    {
        $this->writer()->putEnv($this->shop(), ['STORAGE_LIMIT_MB' => '4096']);

        $env = $this->env();

        $this->assertStringContainsString("# The shop's own database", $env);
        $this->assertStringContainsString('DB_PASSWORD=a-real-password', $env);
        $this->assertLessThan(
            strpos($env, 'DB_DATABASE'),
            strpos($env, 'APP_KEY'),
            'the order of the file changed',
        );
    }

    public function test_a_key_that_was_not_there_is_added(): void
    {
        $this->writer()->putEnv($this->shop(), ['BACKUP_KEEP_DAYS' => '30']);

        $this->assertStringContainsString('BACKUP_KEEP_DAYS=30', $this->env());
    }

    /**
     * PANEL_DOC Section 6: ending a trial must REMOVE the blank
     * LICENCE_PUBLIC_KEY, not set it to something. An empty value is what
     * switches licensing off, so leaving the line there means the new licence
     * is never checked at all — which is the same as no licence.
     */
    public function test_it_removes_a_line_rather_than_blanking_it(): void
    {
        $this->writer()->putEnv(
            $this->shop(),
            set: ['LICENCE_KEY' => 'eyJpZCI6IksdQP.signature'],
            removeIfBlank: ['LICENCE_PUBLIC_KEY'],
        );

        $env = $this->env();

        $this->assertStringContainsString('LICENCE_KEY=eyJpZCI6IksdQP.signature', $env);
        $this->assertStringNotContainsString('LICENCE_PUBLIC_KEY', $env);
    }

    /** A value with a space in it, read back unquoted, is the first word only. */
    public function test_a_value_with_a_space_is_quoted(): void
    {
        $this->writer()->putEnv($this->shop(), ['APP_NAME' => 'Hawler Computer']);

        $this->assertStringContainsString('APP_NAME="Hawler Computer"', $this->env());
    }

    public function test_a_licence_string_is_left_bare(): void
    {
        $licence = 'eyJpZCI6IlBMQkYtOVFEMSJ9.K55R3FXRf87aDqZ_K9QZvJl2Iwd6tVvr';

        $this->writer()->putEnv($this->shop(), ['LICENCE_KEY' => $licence]);

        $this->assertStringContainsString('LICENCE_KEY='.$licence, $this->env());
    }

    /** PANEL_DOC Section 6, step 5: the old file is kept as .env.bak. */
    public function test_the_old_file_is_kept(): void
    {
        $this->writer()->putEnv($this->shop(), ['STORAGE_LIMIT_MB' => '4096']);

        $this->assertFileExists($this->home.'/.env.bak');
        $this->assertSame(self::ENV, file_get_contents($this->home.'/.env.bak'));
    }

    /**
     * Nothing is left lying beside the shop's .env. A stray .env.panel-abc123
     * is a file with a database password in it that nobody will ever look at
     * again.
     */
    public function test_it_leaves_no_temporary_files_behind(): void
    {
        $this->writer()->putEnv($this->shop(), ['STORAGE_LIMIT_MB' => '4096']);

        $this->assertSame([], glob($this->home.'/.env.panel-*') ?: []);
    }

    /** A .env that becomes world-readable is Section 4's Halabja finding again. */
    public function test_the_file_keeps_its_permissions(): void
    {
        chmod($this->home.'/.env', 0600);

        $this->writer()->putEnv($this->shop(), ['STORAGE_LIMIT_MB' => '4096']);

        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->home.'/.env')), -4));
    }

    /**
     * And so does the backup, which is the same secrets in another file.
     *
     * file_put_contents creates at 0644. On a real shop this wrote a
     * world-readable copy of a 0600 .env — the database password and the
     * APP_KEY — handing over everything the original was protecting. Found by
     * looking at the permissions on a really provisioned shop, not by a test:
     * this one was written afterwards.
     */
    public function test_the_backup_keeps_them_too(): void
    {
        chmod($this->home.'/.env', 0600);

        $this->writer()->putEnv($this->shop(), ['STORAGE_LIMIT_MB' => '4096']);

        $this->assertSame(
            '0600',
            substr(sprintf('%o', fileperms($this->home.'/.env.bak')), -4),
            'the backup is a complete copy of the shop’s secrets and must be no more readable than the original',
        );
    }

    /** A shop that is not there must not become a shop with a new .env. */
    public function test_a_missing_env_is_refused_and_nothing_is_created(): void
    {
        $customer = Customer::factory()->create(['shop_home' => '/no/such/shop']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing was changed');

        $this->writer()->putEnv($customer, ['STORAGE_LIMIT_MB' => '4096']);
    }

    public function test_writing_twice_is_not_two_lines(): void
    {
        $writer = $this->writer();
        $customer = $this->shop();

        $writer->putEnv($customer, ['STORAGE_LIMIT_MB' => '2048']);
        $writer->putEnv($customer, ['STORAGE_LIMIT_MB' => '4096']);

        $this->assertSame(1, substr_count($this->env(), 'STORAGE_LIMIT_MB='));
        $this->assertStringContainsString('STORAGE_LIMIT_MB=4096', $this->env());
    }

    /** The file must be readable by Dotenv afterwards, not just look right. */
    public function test_the_result_is_still_a_file_dotenv_can_read(): void
    {
        $this->writer()->putEnv(
            $this->shop(),
            set: ['APP_NAME' => 'Hawler Computer', 'LICENCE_KEY' => 'eyJ.sig'],
            removeIfBlank: ['LICENCE_PUBLIC_KEY'],
        );

        $parsed = Dotenv::parse($this->env());

        $this->assertSame('Hawler Computer', $parsed['APP_NAME']);
        $this->assertSame('eyJ.sig', $parsed['LICENCE_KEY']);
        $this->assertSame('a-real-password', $parsed['DB_PASSWORD']);
        $this->assertArrayNotHasKey('LICENCE_PUBLIC_KEY', $parsed);
        $this->assertStringStartsWith('base64:', $parsed['APP_KEY']);
    }

    /**
     * A shop with a public key set on purpose keeps it.
     *
     * Section 6 asks for "the blank LICENCE_PUBLIC_KEY line" to go, because a
     * blank value is what switches licensing off. A key that is actually set is
     * a deliberate override, and deleting it would quietly move that shop onto
     * the committed default — invisible right up until the day the two differ.
     */
    public function test_a_public_key_that_is_actually_set_is_left_alone(): void
    {
        file_put_contents($this->home.'/.env', "LICENCE_PUBLIC_KEY=MIIBIjANBgkq-a-real-key\nLICENCE_KEY=\n");

        $this->writer()->putEnv(
            $this->shop(),
            set: ['LICENCE_KEY' => 'eyJ.sig'],
            removeIfBlank: ['LICENCE_PUBLIC_KEY'],
        );

        $this->assertStringContainsString('LICENCE_PUBLIC_KEY=MIIBIjANBgkq-a-real-key', $this->env());
    }
}
