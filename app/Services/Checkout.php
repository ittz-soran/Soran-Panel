<?php

namespace App\Services;

use App\Support\ShopEnvironment;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * One git checkout on this server, asked what it is and told to update.
 *
 * There are two that matter: the panel's own code, and the shared shop system
 * that every customer reads. PANEL_DOC Section 3 chose one codebase for many
 * shops on the argument that updating happens once instead of once per
 * customer — this is the thing that makes that true in practice rather than in
 * principle, because a person with a terminal will do it and a person without
 * one will not.
 *
 * Deliberately narrow. It reads, fetches, and fast-forwards. It will not merge,
 * reset, stash, force or check out anything, because every one of those can
 * destroy work that is only on the server — and the panel is the wrong place to
 * find out you have done that.
 */
class Checkout
{
    private const TIMEOUT = 300;

    public function __construct(
        public readonly string $name,
        public readonly string $path,
    ) {}

    /**
     * What this checkout is, right now.
     *
     * @return array{
     *     ok: bool, problem: ?string, branch: ?string, commit: ?string,
     *     subject: ?string, when: ?string, clean: bool,
     *     uncommitted: list<array{status: string, path: string}>
     * }
     */
    public function state(): array
    {
        $blank = ['ok' => false, 'problem' => null, 'branch' => null, 'commit' => null,
            'subject' => null, 'when' => null, 'clean' => false, 'uncommitted' => []];

        if (! is_dir($this->path.'/.git')) {
            return [...$blank, 'problem' => "[{$this->path}] is not a git checkout."];
        }

        try {
            $branch = trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD']));
            $changed = $this->uncommitted();

            if ($branch === 'HEAD') {
                return [...$blank, 'problem' => 'This checkout is not on a branch, so there is nothing to '
                    .'update from. Check out the branch you deployed before updating.'];
            }

            return [
                'ok' => true,
                'problem' => null,
                'branch' => $branch,
                'commit' => trim($this->git(['rev-parse', '--short', 'HEAD'])),
                'subject' => trim($this->git(['log', '-1', '--pretty=%s'])),
                'when' => trim($this->git(['log', '-1', '--pretty=%cI'])),

                // Anything uncommitted here was done on the server by hand, and
                // pulling over it is how that gets lost.
                'clean' => $changed === [],

                // And WHICH files, because the refusal was previously a dead
                // end: it said "look at `git status` there" to somebody holding
                // an iPad, having just run the one command that could have told
                // them. The list was already in hand and thrown away.
                'uncommitted' => $changed,
            ];
        } catch (RuntimeException $e) {
            return [...$blank, 'problem' => $e->getMessage()];
        }
    }

    /**
     * The files changed here but not committed, as git reports them.
     *
     * Porcelain v1 is a stable format on purpose: two status characters, a
     * space, then the path. A rename carries `old -> new`, and the new name is
     * the one worth showing.
     *
     * @return list<array{status: string, path: string}>
     */
    private function uncommitted(): array
    {
        // rtrim, not trim. Porcelain v1 is two status characters then a space,
        // and an unstaged change leaves the first of those blank — ` M file`.
        // Trimming the whole output eats that space off the first line only,
        // and the path then loses its first letter: `EADME.md`.
        $lines = preg_split('/\R/', rtrim($this->git(['status', '--porcelain']))) ?: [];

        $changed = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $path = trim(substr($line, 3));

            if (str_contains($path, ' -> ')) {
                $path = substr($path, strpos($path, ' -> ') + 4);
            }

            $changed[] = [
                'status' => $this->inPlainWords(substr($line, 0, 2)),
                'path' => trim($path, '"'),
            ];
        }

        return $changed;
    }

    /**
     * What git's two letters mean, for somebody who does not read git.
     */
    private function inPlainWords(string $code): string
    {
        $code = trim($code);

        return match (true) {
            $code === '??' => 'new file, not in git',
            str_contains($code, 'D') => 'deleted',
            str_contains($code, 'R') => 'renamed',
            str_contains($code, 'A') => 'added',
            str_contains($code, 'M') => 'changed',
            default => $code,
        };
    }

    /** Ask GitHub what it has, without changing anything here. */
    public function fetch(): void
    {
        $state = $this->state();

        if (! $state['ok']) {
            throw new RuntimeException($state['problem'] ?? 'This checkout cannot be read.');
        }

        /*
         * ⚠️ With the refspec spelled out, not `fetch origin <branch>`.
         *
         * The short form updates FETCH_HEAD and nothing else, so
         * `origin/<branch>` may not exist at all — and then the comparison
         * behind `waiting()` fails with git's own "ambiguous argument" text,
         * which reads like the branch is missing rather than never having been
         * tracked. Found on a checkout whose branch was made locally and
         * pushed, which is exactly how both of these were made.
         *
         * The leading `+` lets the tracking ref move however the remote moved.
         * It is a pointer to what GitHub has, not work of ours to protect.
         */
        $branch = $state['branch'];

        try {
            $this->git(['fetch', 'origin', "+{$branch}:refs/remotes/origin/{$branch}"]);
        } catch (RuntimeException $e) {
            /*
             * ⚠️ **A branch that was merged and then deleted.** This is not an
             * error the operator can act on as git words it:
             *
             *     fatal: couldn't find remote ref claude/shop-provision
             *
             * It happened to the shop system's checkout the ordinary way — the
             * branch it was deployed from was merged into main and GitHub
             * deleted it, as it does — and from then on Updates could say
             * nothing about that checkout at all. Nothing is wrong with the
             * server, nothing is wrong with the code on it, and git's sentence
             * suggests both.
             */
            if (str_contains($e->getMessage(), "couldn't find remote ref")) {
                throw new RuntimeException(sprintf(
                    'This checkout is on [%s], and GitHub no longer has a branch by that name — which is '
                    .'what happens when a branch is merged and deleted. The code here is fine; it is just '
                    .'following something that has gone.%s',
                    $branch,
                    ($default = $this->defaultBranch()) === null
                        ? ' Move it onto the branch you want it to follow.'
                        : " Move it onto [{$default}], which is where that work ended up.",
                ), previous: $e);
            }

            throw $e;
        }
    }

    /**
     * The branch GitHub treats as this repository's main one.
     *
     * Asked of the remote rather than guessed at: `main` and `master` are both
     * ordinary, and a repository is free to call it something else entirely.
     * `--symref` is what makes HEAD readable without cloning anything.
     */
    public function defaultBranch(): ?string
    {
        try {
            $said = $this->git(['ls-remote', '--symref', 'origin', 'HEAD']);
        } catch (RuntimeException) {
            return null;
        }

        return preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD#m', $said, $found) === 1 ? $found[1] : null;
    }

    /**
     * Whether the branch this checkout follows still exists on GitHub.
     *
     * Its own method because the screen asks it to decide what to offer, and a
     * failed fetch is a slow and destructive way to find out.
     */
    public function branchIsGone(): bool
    {
        $branch = $this->state()['branch'];

        if ($branch === null) {
            return false;
        }

        try {
            return trim($this->git(['ls-remote', '--heads', 'origin', $branch])) === '';
        } catch (RuntimeException) {
            // Could not ask. "I do not know" is not "it is gone", and offering
            // to move a checkout on a network hiccup is the wrong way to be
            // wrong.
            return false;
        }
    }

    /**
     * Move this checkout onto the repository's default branch.
     *
     * ⚠️ **Only when nothing can be lost by it**, and both guards matter:
     *
     *   - The working tree must be clean, because anything uncommitted here was
     *     typed on the server by hand and switching branches over it is how
     *     that disappears.
     *   - Every commit here must already be contained in the default branch.
     *     That is the difference between "this branch was merged and tidied
     *     away" — where moving loses nothing at all — and "this branch has work
     *     that never went anywhere", where moving abandons it. The panel cannot
     *     tell those apart by the name, so it asks git.
     *
     * @return string what git said, for the screen
     */
    public function moveToDefaultBranch(): string
    {
        $state = $this->state();

        if (! $state['ok']) {
            throw new RuntimeException($state['problem'] ?? 'This checkout cannot be read.');
        }

        if (! $state['clean']) {
            throw new RuntimeException(
                "[{$this->path}] has uncommitted changes. Whatever they are, they were made on the server "
                .'by hand — commit or discard them there before moving this checkout.',
            );
        }

        $default = $this->defaultBranch()
            ?? throw new RuntimeException('GitHub did not say which branch this repository treats as its main one.');

        if ($default === $state['branch']) {
            throw new RuntimeException("This checkout is already on [{$default}].");
        }

        $this->git(['fetch', 'origin', "+{$default}:refs/remotes/origin/{$default}"]);

        try {
            $this->git(['merge-base', '--is-ancestor', 'HEAD', 'origin/'.$default]);
        } catch (RuntimeException) {
            throw new RuntimeException(sprintf(
                'The commits on [%s] are not all in [%s], so moving this checkout would leave work behind. '
                .'Nothing has been changed. Look at it on the server before deciding.',
                $state['branch'], $default,
            ));
        }

        return trim($this->git(['checkout', '-B', $default, 'origin/'.$default]));
    }

    /**
     * The commits on GitHub that are not here yet, newest first.
     *
     * @return list<array{commit: string, subject: string, when: string}>
     */
    public function waiting(): array
    {
        $state = $this->state();

        if (! $state['ok']) {
            return [];
        }

        // Double quotes: the separator has to be the actual 0x1f byte, not a
        // literal backslash-x-1-f that git would print and explode() never see.
        $log = $this->git([
            'log', "--pretty=%h\x1f%s\x1f%cI", 'HEAD..origin/'.$state['branch'],
        ]);

        $out = [];

        foreach (preg_split('/\R/', trim($log)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            [$commit, $subject, $when] = array_pad(explode("\x1f", $line), 3, '');
            $out[] = ['commit' => $commit, 'subject' => $subject, 'when' => $when];
        }

        return $out;
    }

    /**
     * Take what is waiting — and only if it can be taken without a merge.
     *
     * `--ff-only` is the whole safety of this method. A pull that would need a
     * merge means somebody has committed on the server, and resolving that
     * through a web page with no diff and no way back is not something to
     * offer. It fails, says so, and leaves the checkout exactly as it was.
     *
     * @return string what git said, for the screen
     */
    public function pull(): string
    {
        $state = $this->state();

        if (! $state['ok']) {
            throw new RuntimeException($state['problem'] ?? 'This checkout cannot be read.');
        }

        if (! $state['clean']) {
            throw new RuntimeException(
                "[{$this->path}] has changes that are not committed, and updating would write over them. "
                .'Look at `git status` there and either commit them or put them back before updating.',
            );
        }

        return $this->git(['merge', '--ff-only', 'origin/'.$state['branch']]);
    }

    /**
     * Bring the dependencies up to whatever the new composer.json wants.
     *
     * Not optional after a pull: a new class that arrives without its
     * autoloader entry is a fatal error on the next page, and a panel that
     * fatals is one nobody can use to put itself right.
     */
    public function composer(string $binary): string
    {
        return $this->run([$binary, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'], $this->path);
    }

    /**
     * Throw away everything Laravel compiled from the old code.
     *
     * ⚠️ Not optional, and leaving it out broke the live panel the first time
     * this screen was used. A deployed panel runs `route:cache`, so its routes
     * come from a compiled file — pull code that adds a route and every page
     * dies with RouteNotFoundException, including the one you would use to put
     * it right. The same is true of cached config and compiled views.
     *
     * Clearing destroys nothing: these are all rebuilt on demand. Re-caching is
     * deliberately NOT done here — that belongs to whoever deploys, and doing
     * it mid-update would compile whatever half-finished state the machine is
     * in.
     */
    public function clearCompiledCode(): string
    {
        return $this->run([PHP_BINARY, $this->path.'/artisan', 'optimize:clear'], $this->path);
    }

    /**
     * Bring this application's own database up to the code that just arrived.
     *
     * Without it, "update from the panel" is only true until the first update
     * that carries a migration — and then the panel breaks on a table that is
     * not there, with no way left to put it right except a terminal, which is
     * the thing this screen exists to avoid.
     *
     * ⚠️ Only ever the PANEL's own database. Customers' databases are not
     * touched by any button here: see Updater, which reports which shops are
     * behind rather than migrating them.
     *
     * @return string what artisan said, for the screen
     */
    public function migrate(): string
    {
        return $this->run([PHP_BINARY, $this->path.'/artisan', 'migrate', '--force'], $this->path);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        return $this->run(['git', ...$arguments], $this->path);
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd, env: ShopEnvironment::withoutThePanel());
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                '`%s` failed in [%s]: %s',
                implode(' ', $command), $cwd,
                mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'it said nothing at all', -600),
            ));
        }

        return $process->getOutput().$process->getErrorOutput();
    }
}
