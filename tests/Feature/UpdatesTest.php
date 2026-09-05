<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\User;
use App\Services\Checkout;
use App\Services\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Taking new code from GitHub, from inside the panel.
 *
 * Driven against real git repositories rather than a fake, because everything
 * worth testing here is git's behaviour: what a fast-forward will and will not
 * do, and what `status --porcelain` says about work somebody did on the server.
 * A fake would only prove this file agrees with itself.
 */
class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $origin;

    private string $clone;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->gitIsHere()) {
            $this->markTestSkipped('git is not on this machine.');
        }

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->root = sys_get_temp_dir().'/updates-'.bin2hex(random_bytes(6));
        $this->origin = $this->root.'/origin';
        $this->clone = $this->root.'/clone';

        $this->makeRepository();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root ?? '');

        parent::tearDown();
    }

    private function gitIsHere(): bool
    {
        $process = new Process(['git', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    /** @param list<string> $command */
    private function git(array $command, string $cwd): void
    {
        $process = new Process(['git', ...$command], $cwd, [
            'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 't@example.com',
            'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 't@example.com',
            'HOME' => $this->root,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->fail('git '.implode(' ', $command).': '.$process->getErrorOutput());
        }
    }

    /** An origin with one commit, and a clone of it. */
    private function makeRepository(): void
    {
        mkdir($this->origin, 0755, true);

        $this->git(['init', '-q', '-b', 'main'], $this->origin);
        file_put_contents($this->origin.'/README.md', "one\n");

        // Enough of an application for the update to run artisan against, and
        // it writes down every command it is given so the tests can see which.
        file_put_contents(
            $this->origin.'/artisan',
            "<?php\nfile_put_contents(__DIR__.'/asked', (\$argv[1] ?? '').PHP_EOL, FILE_APPEND);\nexit(0);\n",
        );
        $this->git(['add', '.'], $this->origin);
        $this->git(['commit', '-qm', 'The first commit'], $this->origin);

        $this->git(['clone', '-q', $this->origin, $this->clone], $this->root);
    }

    /** One more commit on the origin, waiting to be taken. */
    private function commitOnOrigin(string $subject, string $file = 'README.md'): void
    {
        file_put_contents($this->origin.'/'.$file, $subject."\n", FILE_APPEND);
        $this->git(['add', '.'], $this->origin);
        $this->git(['commit', '-qm', $subject], $this->origin);
    }

    private function checkout(): Checkout
    {
        return new Checkout('A test checkout', $this->clone);
    }

    // ---- Reading it -------------------------------------------------------

    public function test_it_says_what_is_installed(): void
    {
        $state = $this->checkout()->state();

        $this->assertTrue($state['ok']);
        $this->assertSame('main', $state['branch']);
        $this->assertSame('The first commit', $state['subject']);
        $this->assertTrue($state['clean']);
        $this->assertNotEmpty($state['commit']);
    }

    public function test_a_folder_that_is_not_a_checkout_says_so_rather_than_failing(): void
    {
        $state = (new Checkout('Nowhere', $this->root.'/not-a-repo'))->state();

        $this->assertFalse($state['ok']);
        $this->assertStringContainsString('not a git checkout', (string) $state['problem']);
    }

    /**
     * A checkout on no branch has nothing to update FROM, and the message has
     * to say that rather than reporting a branch called HEAD.
     */
    public function test_a_detached_checkout_says_there_is_nothing_to_update_from(): void
    {
        $this->git(['checkout', '-q', '--detach'], $this->clone);

        $state = $this->checkout()->state();

        $this->assertFalse($state['ok']);
        $this->assertStringContainsString('not on a branch', (string) $state['problem']);
    }

    public function test_it_lists_what_is_waiting_on_github(): void
    {
        $this->commitOnOrigin('A second commit');
        $this->commitOnOrigin('A third commit');

        $checkout = $this->checkout();
        $checkout->fetch();

        $waiting = $checkout->waiting();

        $this->assertCount(2, $waiting);
        $this->assertSame('A third commit', $waiting[0]['subject'], 'newest first');
        $this->assertSame('A second commit', $waiting[1]['subject']);
        $this->assertNotEmpty($waiting[0]['commit']);
        $this->assertNotEmpty($waiting[0]['when']);
    }

    /**
     * ⚠️ Found by running it against the real checkouts, not by writing it.
     *
     * A clone whose remote refspec is narrowed — `--single-branch`, or a clone
     * of one branch — never creates `origin/<other>`. `git fetch origin
     * <branch>` then updates FETCH_HEAD and nothing else, because git only
     * updates tracking refs the refspec covers. The comparison behind
     * `waiting()` fails with git's own "ambiguous argument" text, which reads
     * like the branch is missing rather than never having been tracked.
     *
     * The real shop-system checkout is exactly this: cloned for `main`, working
     * on another branch.
     */
    public function test_it_can_read_a_branch_the_clone_does_not_track(): void
    {
        $this->git(['checkout', '-qb', 'side'], $this->origin);
        file_put_contents($this->origin.'/README.md', "on the side\n", FILE_APPEND);
        $this->git(['add', '.'], $this->origin);
        $this->git(['commit', '-qm', 'A commit on the side branch'], $this->origin);

        // A clone that only ever tracks main, then switched to the branch —
        // which is how both real checkouts on the server are set up.
        $this->git(['config', 'remote.origin.fetch', '+refs/heads/main:refs/remotes/origin/main'], $this->clone);
        $this->git(['checkout', '-qb', 'side'], $this->clone);
        $this->git(['update-ref', '-d', 'refs/remotes/origin/side'], $this->clone);

        $checkout = $this->checkout();
        $checkout->fetch();

        $waiting = $checkout->waiting();

        $this->assertCount(1, $waiting);
        $this->assertSame('A commit on the side branch', $waiting[0]['subject']);
    }

    // ---- Taking it --------------------------------------------------------

    public function test_it_takes_what_is_waiting(): void
    {
        $this->commitOnOrigin('A second commit');

        $checkout = $this->checkout();
        $checkout->fetch();
        $checkout->pull();

        $this->assertSame('A second commit', $checkout->state()['subject']);
    }

    /**
     * ⚠️ The guard the whole thing rests on.
     *
     * Anything uncommitted in the checkout was done on the server by hand, and
     * a pull over it is how that gets lost. There is no diff on this screen and
     * no way back from it, so it refuses instead.
     */
    public function test_it_refuses_to_pull_over_work_done_on_the_server(): void
    {
        $this->commitOnOrigin('A second commit');
        file_put_contents($this->clone.'/README.md', "edited on the server\n", FILE_APPEND);

        $checkout = $this->checkout();
        $checkout->fetch();

        try {
            $checkout->pull();
            $this->fail('it pulled over uncommitted work');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not committed', $e->getMessage());
        }

        // Still there, and still on the old commit.
        $this->assertStringContainsString('edited on the server', file_get_contents($this->clone.'/README.md'));
        $this->assertSame('The first commit', $checkout->state()['subject']);
    }

    /**
     * A history that has diverged needs a merge, and a merge needs judgement
     * this screen cannot offer. It fails and leaves the checkout alone.
     */
    public function test_a_history_that_has_diverged_is_refused_rather_than_merged(): void
    {
        $this->commitOnOrigin('Their commit');

        file_put_contents($this->clone.'/OURS.md', "ours\n");
        $this->git(['add', '.'], $this->clone);
        $this->git(['commit', '-qm', 'Our commit'], $this->clone);

        $checkout = $this->checkout();
        $checkout->fetch();

        $this->expectException(RuntimeException::class);

        $checkout->pull();
    }

    // ---- The screen -------------------------------------------------------

    public function test_the_screen_is_behind_the_sign_in(): void
    {
        auth()->logout();

        $this->get(route('updates'))->assertRedirect(route('login'));
    }

    public function test_the_screen_opens_without_asking_github(): void
    {
        $this->get(route('updates'))
            ->assertOk()
            ->assertSee('Check GitHub');
    }

    public function test_updating_is_written_down(): void
    {
        $this->commitOnOrigin('A second commit');

        $this->swap(Updater::class, new class($this->clone) extends Updater
        {
            public function __construct(private readonly string $path) {}

            public function checkouts(): array
            {
                return ['panel' => new Checkout('The panel', $this->path)];
            }
        });

        $this->post(route('updates.store'), ['checkout' => 'panel'])
            ->assertRedirect(route('updates'))
            ->assertSessionHas('success');

        $logged = Action::where('action', 'codebase.updated')->first();

        $this->assertNotNull($logged);
        $this->assertSame(auth()->id(), $logged->user_id);
        $this->assertSame(1, $logged->detail['commits']);
        $this->assertNotSame($logged->detail['was'], $logged->detail['now']);
    }

    /**
     * ⚠️ The omission that broke the live panel the first time this screen was
     * used.
     *
     * A deployed panel runs `route:cache`, so its routes come from a compiled
     * file. Pull code that adds a route, leave that file, and every page dies
     * with RouteNotFoundException — including the one you would use to put it
     * right.
     */
    public function test_updating_throws_away_the_code_compiled_from_the_old_version(): void
    {
        $this->commitOnOrigin('A second commit');

        // An artisan that records being asked, standing in for the real one.
        // Written straight into the checkout: this is about what the clear
        // runs, and nothing here needs it committed.
        file_put_contents(
            $this->clone.'/artisan',
            "<?php\nfile_put_contents(__DIR__.'/cleared', \$argv[1] ?? '');\nexit(0);\n",
        );

        $this->checkout()->clearCompiledCode();

        $this->assertSame('optimize:clear', file_get_contents($this->clone.'/cleared'));
    }

    /**
     * A clear that fails must not look like a failed update. The code IS
     * updated by then, and saying otherwise sends somebody to undo it.
     */
    public function test_a_failed_clear_is_a_warning_and_not_a_failed_update(): void
    {
        // An artisan that fails, arriving WITH the update — so the pull
        // succeeds and the clear is the only thing that goes wrong. Deleting
        // the local one instead would dirty the tree, and the pull would
        // refuse first, which tests something else entirely.
        file_put_contents($this->origin.'/artisan', "<?php\nexit(1);\n");
        $this->git(['add', '.'], $this->origin);
        $this->git(['commit', '-qm', 'An artisan that fails'], $this->origin);

        $this->swap(Updater::class, new class($this->clone) extends Updater
        {
            public function __construct(private readonly string $path) {}

            public function checkouts(): array
            {
                return ['panel' => new Checkout('The panel', $this->path)];
            }
        });

        $this->post(route('updates.store'), ['checkout' => 'panel'])
            ->assertSessionHas('warning', fn (string $said) => str_contains($said, 'optimize:clear'));

        // And it really did update.
        $this->assertSame('An artisan that fails', $this->checkout()->state()['subject']);
    }

    /** @return list<string> the artisan commands the update ran */
    private function artisanWasAsked(): array
    {
        $path = $this->clone.'/asked';

        return is_file($path)
            ? array_values(array_filter(array_map('trim', file($path))))
            : [];
    }

    private function swapUpdaterFor(string $key): void
    {
        $this->swap(Updater::class, new class($this->clone, $key) extends Updater
        {
            public function __construct(private readonly string $path, private readonly string $key) {}

            public function checkouts(): array
            {
                return [$this->key => new Checkout('A checkout', $this->path)];
            }
        });
    }

    /**
     * ⚠️ Without this, "update from the panel" is only true until the first
     * update that carries a migration — and then the panel breaks on a missing
     * column, and the screen that would fix it is the one that just broke.
     */
    public function test_updating_the_panel_brings_its_own_database_with_it(): void
    {
        $this->commitOnOrigin('A second commit');

        $this->swapUpdaterFor('panel');

        $this->post(route('updates.store'), ['checkout' => 'panel'])->assertSessionHas('success');

        $this->assertContains('migrate', $this->artisanWasAsked());
        $this->assertContains('optimize:clear', $this->artisanWasAsked());
    }

    /**
     * ⚠️ And the shop system's update must NOT. That codebase is shared, its
     * `migrate` would run against whichever database the environment happened
     * to point at, and customers' data is not something a button labelled
     * "update code" gets to touch. Updater says which shops are behind instead.
     */
    public function test_updating_the_shop_system_migrates_nothing(): void
    {
        $this->commitOnOrigin('A second commit');

        $this->swapUpdaterFor('shop_system');

        $this->post(route('updates.store'), ['checkout' => 'shop_system'])->assertSessionHas('success');

        $this->assertNotContains('migrate', $this->artisanWasAsked());
        $this->assertContains('optimize:clear', $this->artisanWasAsked());
    }

    public function test_updating_something_that_is_not_a_checkout_is_refused(): void
    {
        $this->post(route('updates.store'), ['checkout' => 'the-moon'])
            ->assertSessionHasErrors('checkout');
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            is_dir($path.'/'.$entry) && ! is_link($path.'/'.$entry)
                ? $this->rmrf($path.'/'.$entry)
                : @unlink($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
