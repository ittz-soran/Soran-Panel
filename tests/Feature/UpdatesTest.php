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

        // Where the borrowed look is taken from and where it lands. Without
        // these, updating the shop system in a test would read the real .env's
        // /home/soransto path and write into this repository's own public/.
        mkdir($this->root.'/panel-public', 0777, true);
        $this->app->usePublicPath($this->root.'/panel-public');

        config([
            'panel.shops.shared_artisan' => $this->clone.'/artisan',
            'panel.shops.public_root' => $this->root.'/public_html',
        ]);
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
        // The compiled look the panel borrows from this codebase — Section 10.
        // Committed here because it is committed in the real shop system, and
        // because updating the shop system now refreshes the panel's copy of it.
        mkdir($this->origin.'/public/build/assets', 0777, true);
        file_put_contents($this->origin.'/public/build/manifest.json', '"the first build"');
        file_put_contents($this->origin.'/public/build/assets/app-first.css', 'body{}');

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

    /**
     * The refusal has to name the files, not just refuse.
     *
     * It used to say "look at `git status` there" — to somebody holding an iPad,
     * having itself just run the one command that could have answered. The list
     * was in hand and thrown away, so the guard was correct and the screen was a
     * dead end.
     */
    public function test_it_says_which_files_are_uncommitted(): void
    {
        // One tracked file edited, one file git has never seen.
        file_put_contents($this->clone.'/README.md', "changed by hand\n", FILE_APPEND);
        file_put_contents($this->clone.'/notes.txt', "left on the server\n");

        $state = $this->checkout()->state();

        $this->assertFalse($state['clean']);

        $paths = array_column($state['uncommitted'], 'path');
        sort($paths);

        $this->assertSame(['README.md', 'notes.txt'], $paths);

        $said = array_column($state['uncommitted'], 'status', 'path');

        // In words rather than git's two letters — the reader of this screen is
        // not obliged to know what `??` means.
        $this->assertSame('changed', $said['README.md']);
        $this->assertSame('new file, not in git', $said['notes.txt']);
    }

    /** A clean checkout offers no list at all, rather than an empty box. */
    public function test_a_clean_checkout_has_nothing_to_list(): void
    {
        $state = $this->checkout()->state();

        $this->assertTrue($state['clean']);
        $this->assertSame([], $state['uncommitted']);
    }

    /** And the screen shows them, which is the whole point. */
    public function test_the_screen_names_the_uncommitted_files(): void
    {
        file_put_contents($this->clone.'/README.md', "changed by hand\n", FILE_APPEND);
        $this->useTheFixtureAsTheOnlyCheckout();

        $this->get(route('updates'))
            ->assertOk()
            ->assertSee('are not committed')
            ->assertSee('README.md')
            ->assertSee('changed');
    }

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

    /**
     * ⚠️ The panel has no stylesheet of its own — Section 10. It wears a COPY of
     * the shop system's compiled build, so pulling new shop-system code moves
     * the panel's markup and would leave its stylesheet behind. That drift is
     * silent until a screen looks wrong, and this is the only moment it can
     * begin.
     */
    public function test_updating_the_shop_system_refreshes_the_look_the_panel_borrows(): void
    {
        // The panel is wearing what it was given at deploy time.
        mkdir(public_path('build/assets'), 0777, true);
        file_put_contents(public_path('build/manifest.json'), '"the first build"');

        // And the shop system's next commit rebuilds it.
        file_put_contents($this->origin.'/public/build/manifest.json', '"the second build"');
        $this->commitOnOrigin('A rebuilt front end', 'public/build/assets/app-second.css');

        $this->swapUpdaterFor('shop_system');

        $this->post(route('updates.store'), ['checkout' => 'shop_system'])->assertSessionHas('success');

        $this->assertSame('"the second build"', file_get_contents(public_path('build/manifest.json')));
    }

    /**
     * ⚠️ A panel with no borrowed stylesheet serves unstyled HTML on every
     * screen — including this one. So this card has to say what is wrong in
     * words, not by looking wrong: looking wrong is the symptom, and by then
     * every card on the page looks the same.
     */
    public function test_the_screen_says_when_the_borrowed_look_is_missing(): void
    {
        $this->get(route('updates'))
            ->assertOk()
            ->assertSee('The look borrowed from the shop system')
            ->assertSee('serving unstyled HTML');

        mkdir(public_path('build/assets'), 0777, true);
        file_put_contents(public_path('build/manifest.json'), '"a build"');

        $this->get(route('updates'))
            ->assertOk()
            ->assertDontSee('serving unstyled HTML');
    }

    public function test_the_button_takes_the_look_again(): void
    {
        $this->post(route('updates.look'))->assertSessionHas('success');

        $this->assertSame('"the first build"', file_get_contents(public_path('build/manifest.json')));
    }

    /** And it says why rather than half-doing it, when there is nothing to take. */
    public function test_the_button_refuses_when_there_is_nothing_to_take(): void
    {
        mkdir(public_path('build'), 0777, true);
        file_put_contents(public_path('build/manifest.json'), '"what it is wearing"');

        config(['panel.shops.shared_artisan' => $this->root.'/not-here/artisan']);

        $this->post(route('updates.look'))->assertSessionHas('warning');

        $this->assertSame('"what it is wearing"', file_get_contents(public_path('build/manifest.json')));
    }

    public function test_updating_something_that_is_not_a_checkout_is_refused(): void
    {
        $this->post(route('updates.store'), ['checkout' => 'the-moon'])
            ->assertSessionHasErrors('checkout');
    }

    // ---- A branch that was merged and deleted -----------------------------

    /**
     * Put the clone on a branch and then delete that branch from the origin.
     *
     * This is not an exotic state: it is what every branch looks like a moment
     * after its pull request is merged, because GitHub deletes it. The shop
     * system's checkout reached it the ordinary way and Updates could then say
     * nothing about that checkout at all.
     */
    private function strandOnBranch(string $branch = 'feature', bool $merged = true): void
    {
        $this->git(['branch', $branch], $this->origin);
        $this->git(['fetch', '-q', 'origin'], $this->clone);
        $this->git(['checkout', '-q', '-b', $branch, 'origin/'.$branch], $this->clone);

        if (! $merged) {
            // Work that only ever existed on this branch. Moving off it would
            // abandon the commit, which is the case the guard exists for.
            file_put_contents($this->clone.'/only-here.txt', "never pushed\n");
            $this->git(['add', '.'], $this->clone);
            $this->git(['commit', '-qm', 'Work that never reached main'], $this->clone);
        }

        $this->git(['branch', '-D', $branch], $this->origin);
    }

    /**
     * git says `fatal: couldn't find remote ref claude/shop-provision`, which
     * reads like the server is broken. Nothing is broken — the checkout is
     * following a branch that has gone.
     */
    public function test_a_branch_that_was_merged_and_deleted_is_explained_rather_than_quoted(): void
    {
        $this->strandOnBranch();

        try {
            $this->checkout()->fetch();
            $this->fail('fetching from a deleted branch reported success');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('GitHub no longer has a branch by that name', $e->getMessage());
            $this->assertStringContainsString('feature', $e->getMessage());
            $this->assertStringContainsString('[main]', $e->getMessage(), 'it did not say where to go instead');
            $this->assertStringNotContainsString('fatal:', $e->getMessage());
        }
    }

    public function test_it_knows_whether_the_branch_still_exists(): void
    {
        $this->assertFalse($this->checkout()->branchIsGone(), 'a live branch was called gone');

        $this->strandOnBranch();

        $this->assertTrue($this->checkout()->branchIsGone());
    }

    /** `main` and `master` are both ordinary, and a repository may use neither. */
    public function test_the_default_branch_is_asked_of_the_remote_rather_than_guessed(): void
    {
        $this->assertSame('main', $this->checkout()->defaultBranch());

        // Renaming the checked-out branch moves HEAD with it.
        $this->git(['branch', '-m', 'main', 'trunk'], $this->origin);

        $this->assertSame('trunk', $this->checkout()->defaultBranch());
    }

    // ---- Moving it ---------------------------------------------------------

    public function test_a_stranded_checkout_can_be_moved_onto_the_default_branch(): void
    {
        $this->strandOnBranch();

        $said = $this->checkout()->moveToDefaultBranch();

        $this->assertSame('main', $this->checkout()->state()['branch']);
        $this->assertNotSame('', $said);

        // And it can see GitHub again, which is the whole point.
        $this->commitOnOrigin('Something new');
        $this->checkout()->fetch();

        $this->assertCount(1, $this->checkout()->waiting());
    }

    /**
     * ⚠️ The guard that matters. A branch GitHub deleted after merging holds
     * nothing that is not in `main`; a branch somebody deleted by mistake may
     * hold work nobody else has. The panel cannot tell them apart by name, so
     * it asks git — and refuses rather than abandoning the commit.
     */
    public function test_it_refuses_to_move_when_the_branch_holds_work_that_never_merged(): void
    {
        $this->strandOnBranch(merged: false);

        try {
            $this->checkout()->moveToDefaultBranch();
            $this->fail('a branch with unmerged work was abandoned');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('would leave work behind', $e->getMessage());
        }

        $this->assertSame('feature', $this->checkout()->state()['branch'], 'it moved anyway');
        $this->assertFileExists($this->clone.'/only-here.txt');
    }

    /** Anything uncommitted here was typed on the server by hand. */
    public function test_it_refuses_to_move_over_uncommitted_work(): void
    {
        $this->strandOnBranch();

        file_put_contents($this->clone.'/README.md', "edited on the server\n", FILE_APPEND);

        try {
            $this->checkout()->moveToDefaultBranch();
            $this->fail('uncommitted work was written over');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('uncommitted changes', $e->getMessage());
        }

        $this->assertSame('feature', $this->checkout()->state()['branch']);
        $this->assertStringContainsString('edited on the server', file_get_contents($this->clone.'/README.md'));
    }

    // ---- The screen --------------------------------------------------------

    /** The screen reads the real checkouts, so point one of them at the fixture. */
    private function useTheFixtureAsTheOnlyCheckout(): void
    {
        $this->swap(Updater::class, new class($this->clone) extends Updater
        {
            public function __construct(private readonly string $path) {}

            public function checkouts(): array
            {
                return ['panel' => new Checkout('The panel', $this->path)];
            }
        });
    }

    public function test_the_screen_says_what_happened_and_offers_the_move(): void
    {
        $this->strandOnBranch();
        $this->useTheFixtureAsTheOnlyCheckout();

        $this->get(route('updates', ['check' => 1]))
            ->assertOk()
            ->assertSee('GitHub no longer has a branch by that name')
            ->assertSee('Move it onto')
            // Not red: nothing here is broken, and sending somebody to the
            // server to look for damage that is not there wastes an evening.
            ->assertSee('alert-warning', escape: false)
            // The details stay readable — this is a working checkout.
            ->assertSee('feature');
    }

    public function test_moving_from_the_screen_works_and_is_written_down(): void
    {
        $this->strandOnBranch();
        $this->useTheFixtureAsTheOnlyCheckout();

        $this->post(route('updates.branch'), ['checkout' => 'panel'])
            ->assertSessionHas('success', fn (string $said) => str_contains($said, 'now follows')
                && str_contains($said, 'main'));

        $this->assertSame('main', $this->checkout()->state()['branch']);

        $action = Action::where('action', 'codebase.branch_changed')->firstOrFail();

        $this->assertSame('feature', $action->detail['from']);
        $this->assertSame('main', $action->detail['to']);
        $this->assertSame('Soran', $action->user->name);
    }

    public function test_a_checkout_already_on_the_default_branch_is_told_so(): void
    {
        $this->useTheFixtureAsTheOnlyCheckout();

        $this->post(route('updates.branch'), ['checkout' => 'panel'])
            ->assertSessionHas('warning', fn (string $said) => str_contains($said, 'already on [main]'));
    }

    public function test_moving_a_branch_is_behind_the_sign_in(): void
    {
        auth()->logout();

        $this->post(route('updates.branch'), ['checkout' => 'panel'])->assertRedirect(route('login'));
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
