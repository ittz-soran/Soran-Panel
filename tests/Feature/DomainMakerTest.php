<?php

namespace Tests\Feature;

use App\Services\CpanelDomainMaker;
use App\Services\ManualDomainMaker;
use App\Support\Uapi;
use RuntimeException;
use Tests\TestCase;

/**
 * Pointing a domain at a shop's folder.
 *
 * ⚠️ Every test here is really the same test: **does the document root come
 * out relative to the home folder?** That is the one thing this class exists
 * for. Two domains were once created by hand with the absolute path, and cPanel
 * appended it to home:
 *
 *     documentroot: /home/soransto/home/soransto/public_html/panel
 *     homedir:      /home/soransto
 *
 * Both 404'd for every request, including static files, which reads like a
 * missing vhost rather than a wrong path. It cost most of a night.
 */
class DomainMakerTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    private string|false $realHome;

    private function maker(): CpanelDomainMaker
    {
        $spy = new class($this->calls) extends Uapi
        {
            /** @param list<array{string, string, array<string, mixed>}> $calls */
            public function __construct(public array &$calls) {}

            public function call(string $module, string $function, array $arguments): array
            {
                $this->calls[] = [$module, $function, $arguments];

                return ['result' => ['status' => 1, 'errors' => null]];
            }
        };

        return new CpanelDomainMaker($spy);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->realHome = getenv('HOME');

        config(['panel.cpanel.home' => '/home/soransto']);
        putenv('HOME=/home/soransto');
    }

    protected function tearDown(): void
    {
        is_string($this->realHome) ? putenv('HOME='.$this->realHome) : putenv('HOME');

        parent::tearDown();
    }

    public function test_the_document_root_is_given_relative_to_the_home_folder(): void
    {
        $this->maker()->create('bazaar.soranstore.com', '/home/soransto/public_html/bazaar');

        [$module, $function, $arguments] = $this->calls[0];

        $this->assertSame('SubDomain', $module);
        $this->assertSame('addsubdomain', $function);

        $this->assertSame(
            'public_html/bazaar',
            $arguments['dir'],
            'the absolute path was passed straight through — cPanel would append it to the home folder',
        );

        $this->assertStringNotContainsString('/home/soransto', $arguments['dir']);
    }

    /**
     * The web-request case, and the reason this stopped working in the field:
     * `$HOME` is set by a login shell, so under PHP-FPM there is none. The
     * document root still has to come out relative, because the browser is
     * where shops are actually made.
     */
    public function test_it_works_in_a_web_request_where_home_is_not_in_the_environment(): void
    {
        if (! function_exists('posix_getpwuid') || ! function_exists('posix_getuid')) {
            $this->markTestSkipped('the posix extension is not here to ask');
        }

        config(['panel.cpanel.home' => '']);
        putenv('HOME');

        $home = rtrim((string) posix_getpwuid(posix_getuid())['dir'], '/');

        $this->maker()->create('bazaar.soranstore.com', $home.'/public_html/bazaar');

        [, , $arguments] = $this->calls[0];

        $this->assertSame('public_html/bazaar', $arguments['dir']);
    }

    /** cPanel wants the label and the parent separately, not the whole host. */
    public function test_the_host_is_split_into_a_label_and_the_domain_it_hangs_off(): void
    {
        $this->maker()->create('bazaar.soranstore.com', '/home/soransto/public_html/bazaar');

        [, , $arguments] = $this->calls[0];

        $this->assertSame('bazaar', $arguments['domain']);
        $this->assertSame('soranstore.com', $arguments['rootdomain']);
    }

    /**
     * Split at the FIRST dot: `till.bazaar.soranstore.com` is `till` under
     * `bazaar.soranstore.com`, which is how cPanel reads it too.
     */
    public function test_a_deeper_subdomain_hangs_off_its_own_parent(): void
    {
        $this->maker()->create('till.bazaar.soranstore.com', '/home/soransto/public_html/till');

        [, , $arguments] = $this->calls[0];

        $this->assertSame('till', $arguments['domain']);
        $this->assertSame('bazaar.soranstore.com', $arguments['rootdomain']);
    }

    /** A bare domain is not a subdomain, and cPanel cannot add one. */
    public function test_a_domain_with_nothing_in_front_of_it_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a subdomain');

        $this->maker()->create('soranstore.com', '/home/soransto/public_html/x');
    }

    /** A folder outside the account is one cPanel could never serve. */
    public function test_a_document_root_outside_the_home_folder_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not inside');

        $this->maker()->create('bazaar.soranstore.com', '/var/www/bazaar');
    }

    /** cPanel would otherwise turn a dotted label into an underscored one. */
    public function test_it_tells_cpanel_not_to_invent_a_different_name(): void
    {
        $this->maker()->create('bazaar.soranstore.com', '/home/soransto/public_html/bazaar');

        [, , $arguments] = $this->calls[0];

        $this->assertSame(1, $arguments['disallowdot']);
    }

    /**
     * A rollback runs when something has already gone wrong. One that throws
     * buries the error that caused it.
     */
    public function test_removing_a_domain_that_cannot_be_removed_reports_rather_than_throws(): void
    {
        $angry = new class extends Uapi
        {
            public function call(string $module, string $function, array $arguments): array
            {
                throw new RuntimeException('cPanel said no');
            }
        };

        $left = (new CpanelDomainMaker($angry))->remove('bazaar.soranstore.com');

        $this->assertCount(1, $left);
        $this->assertStringContainsString('bazaar.soranstore.com', $left[0]);
    }

    /** On a laptop the panel points nothing, and says so rather than pretending. */
    public function test_the_manual_maker_does_nothing_and_admits_it(): void
    {
        $manual = new ManualDomainMaker;

        $manual->create('bazaar.soranstore.com', '/anywhere');

        $this->assertSame([], $manual->remove('bazaar.soranstore.com'));
        $this->assertFalse($manual->isAutomatic());
        $this->assertStringContainsString('by hand', $manual->describe());
    }
}
