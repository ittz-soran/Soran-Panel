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
     *     subject: ?string, when: ?string, clean: bool
     * }
     */
    public function state(): array
    {
        $blank = ['ok' => false, 'problem' => null, 'branch' => null, 'commit' => null,
            'subject' => null, 'when' => null, 'clean' => false];

        if (! is_dir($this->path.'/.git')) {
            return [...$blank, 'problem' => "[{$this->path}] is not a git checkout."];
        }

        try {
            $branch = trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD']));

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
                'clean' => trim($this->git(['status', '--porcelain'])) === '',
            ];
        } catch (RuntimeException $e) {
            return [...$blank, 'problem' => $e->getMessage()];
        }
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

        $this->git(['fetch', 'origin', "+{$branch}:refs/remotes/origin/{$branch}"]);
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
