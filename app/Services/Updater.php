<?php

namespace App\Services;

use App\Models\Action;
use RuntimeException;

/**
 * Taking new code from GitHub, from inside the panel — Section 3's promise kept.
 *
 * Section 3 chose one codebase for many shops on the argument that updating
 * happens once rather than once per customer. That is only true if updating is
 * something Soran will actually do, and until now it meant a terminal, a git
 * pull, a composer install and remembering the order. This is the same thing on
 * a screen.
 *
 * Two checkouts, and they are not the same kind of risk:
 *
 *   - **The shop system** is what every customer runs. Updating it is the point
 *     of the architecture. It can also bring migrations, and shops do not
 *     migrate themselves — so this says which shops are now behind rather than
 *     quietly running `migrate` on other people's databases as a side effect of
 *     a button labelled "update code".
 *   - **The panel** is Soran's own. Updating it changes the code serving the
 *     request that asked for it, so it records where it came from: a panel that
 *     will not boot is one you cannot use to put itself back.
 */
class Updater
{
    /** @return array<string, Checkout> */
    public function checkouts(): array
    {
        $shared = (string) config('panel.shops.shared_artisan');

        return [
            'shop_system' => new Checkout('The shop system', dirname($shared)),
            'panel' => new Checkout('The panel', base_path()),
        ];
    }

    /**
     * Both checkouts, as they are, with whatever GitHub is holding.
     *
     * @param  bool  $askGithub  false on a plain page load, so opening the
     *                           screen never waits on the network
     * @return array<string, array<string, mixed>>
     */
    public function look(bool $askGithub = false): array
    {
        $seen = [];

        foreach ($this->checkouts() as $key => $checkout) {
            $state = $checkout->state();
            $waiting = [];
            $asked = false;
            $problem = $state['problem'];

            $stranded = false;

            if ($state['ok'] && $askGithub) {
                try {
                    $checkout->fetch();
                    $waiting = $checkout->waiting();
                    $asked = true;
                } catch (RuntimeException $e) {
                    $problem = $e->getMessage();

                    // Asked only when the fetch already failed, because it is
                    // another round trip to GitHub and the answer only matters
                    // in the one case where the screen has something to offer.
                    $stranded = $checkout->branchIsGone();
                }
            }

            $seen[$key] = [
                ...$state,
                'problem' => $problem,
                'name' => $checkout->name,
                'path' => $checkout->path,
                'waiting' => $waiting,
                'asked' => $asked,

                // The branch this follows is gone from GitHub, and the screen
                // can offer to move it. See Checkout::moveToDefaultBranch.
                'stranded' => $stranded,
                'default_branch' => $stranded ? $checkout->defaultBranch() : null,
            ];
        }

        return $seen;
    }

    /**
     * Move a checkout off a branch GitHub no longer has.
     *
     * Its own action rather than part of `update()`, because it is a different
     * decision: updating takes commits somebody wrote for this branch, and this
     * changes which branch is being followed at all. The guards live in
     * `Checkout::moveToDefaultBranch` — clean tree, and nothing here that is
     * not already in the branch it moves to.
     *
     * @return array{was: ?string, now: ?string, said: string}
     */
    public function moveToDefaultBranch(string $which): array
    {
        $checkout = $this->checkouts()[$which]
            ?? throw new RuntimeException("There is no checkout called [{$which}].");

        $was = $checkout->state()['branch'];
        $said = $checkout->moveToDefaultBranch();
        $now = $checkout->state()['branch'];

        Action::record('codebase.branch_changed', null, [
            'checkout' => $checkout->name,
            'path' => $checkout->path,
            'from' => $was,
            'to' => $now,
        ]);

        return ['was' => $was, 'now' => $now, 'said' => $said];
    }

    /**
     * Take what is waiting for one of them.
     *
     * @return array{was: string, now: string, took: int, said: list<string>, warnings: list<string>}
     */
    public function update(string $which): array
    {
        $checkout = $this->checkouts()[$which]
            ?? throw new RuntimeException("There is no checkout called [{$which}].");

        $before = $checkout->state();

        if (! $before['ok']) {
            throw new RuntimeException($before['problem'] ?? 'That checkout cannot be read.');
        }

        $checkout->fetch();
        $waiting = $checkout->waiting();

        if ($waiting === []) {
            throw new RuntimeException("{$checkout->name} is already up to date.");
        }

        $said = [trim($checkout->pull())];
        $warnings = [];

        /*
         * Composer, and only when the pull touched what it manages. Running it
         * every time turns a ten-second update into a two-minute one on shared
         * hosting; skipping it when composer.json changed is a fatal error on
         * the next page, from a class that arrived without an autoloader entry.
         */
        if ($this->touchedDependencies($said[0])) {
            try {
                $said[] = trim($checkout->composer($this->composerBinary()));
            } catch (RuntimeException $e) {
                $warnings[] = 'The code is updated, but installing its dependencies failed — run '
                    ."`composer install --no-dev --optimize-autoloader` in [{$checkout->path}] by hand. "
                    .$e->getMessage();
            }
        }

        /*
         * The caches, always, and after composer so the autoloader is already
         * right. A deployed panel caches its routes: new code with a new route
         * and a stale route cache is a 500 on every page, from the same file
         * that would tell you why. It broke the live panel exactly once.
         */
        try {
            $checkout->clearCompiledCode();
        } catch (RuntimeException $e) {
            $warnings[] = 'The code is updated, but clearing the old compiled routes and config failed — '
                ."run `php artisan optimize:clear` in [{$checkout->path}] before using it. ".$e->getMessage();
        }

        /*
         * The panel's own tables, when the panel is what moved.
         *
         * A pull that brings a migration and does not run it is a panel that
         * breaks on a missing column, and the screen that would fix it is the
         * one that just broke. Its own database only — a customer's is never
         * migrated by a button here.
         */
        if ($which === 'panel') {
            try {
                $said[] = trim($checkout->migrate());
            } catch (RuntimeException $e) {
                $warnings[] = 'The code is updated, but its migrations did not run — the panel may not work '
                    ."until `php artisan migrate --force` is run in [{$checkout->path}]. ".$e->getMessage();
            }
        }

        /*
         * And every shop's, when the shared codebase moved.
         *
         * Section 3 gives each shop its own bootstrap/cache and compiled views,
         * built from the shared code. Leave them after an update and a shop is
         * running yesterday's views against today's classes — which is a broken
         * shop for a customer, from a button pressed here.
         *
         * Safe in a way `migrate` is not: a cleared cache is rebuilt on the
         * next page, and no data is touched. That is why this happens
         * automatically and migrating does not.
         */
        if ($which === 'shop_system') {
            $warnings = [...$warnings, ...$this->clearEveryShop()];

            /*
             * And the look the panel borrows from it — Section 10.
             *
             * The panel has no stylesheet of its own: it wears a COPY of the
             * shop system's compiled build. So pulling new shop-system code
             * moves the panel's markup and leaves its stylesheet where it was,
             * and nothing says so until a screen looks wrong. This is the only
             * moment that drift can begin, which makes it the moment to close
             * it — leaving it to a deploy step is what let the live panel end
             * up wearing a build three weeks older than its own markup.
             *
             * A warning rather than a failure: the code is already pulled, and
             * BorrowedLook never takes the old copy away unless it has a whole
             * new one to put there, so the panel is still wearing something.
             */
            try {
                app(BorrowedLook::class)->refresh();
            } catch (RuntimeException $e) {
                $warnings[] = 'The shop system is updated, but the look the panel borrows from it was not '
                    .'refreshed, so the panel’s own screens may not match their stylesheet — run '
                    .'`php artisan panel:assets`. '.$e->getMessage();
            }
        }

        $after = $checkout->state();

        Action::record('codebase.updated', null, [
            'checkout' => $which,
            'path' => $checkout->path,
            'branch' => $before['branch'],
            'was' => $before['commit'],
            'now' => $after['commit'],
            'commits' => count($waiting),
        ]);

        return [
            'was' => (string) $before['commit'],
            'now' => (string) $after['commit'],
            'took' => count($waiting),
            'said' => array_values(array_filter($said)),
            'warnings' => $warnings,
        ];
    }

    /**
     * Throw away what every shop compiled from the old shared code.
     *
     * Resolved here rather than injected, because this is the one path that
     * needs it and the screen's other work has nothing to do with shops.
     *
     * @return list<string> the shops that could not be cleared
     */
    private function clearEveryShop(): array
    {
        // The same thing the Updates screen's own button does, so there is one
        // implementation of "clear them all" rather than two that can drift.
        $stubborn = app(ShopControls::class)->clearEveryShop()['stubborn'];

        return $stubborn === [] ? [] : [
            'These shops still have the old compiled code and may not work until it is cleared: '
            .implode(', ', $stubborn).'.',
        ];
    }

    /**
     * Did the pull change anything composer cares about?
     *
     * Read off git's own summary of what it changed, which names every file.
     */
    private function touchedDependencies(string $whatGitSaid): bool
    {
        return str_contains($whatGitSaid, 'composer.json')
            || str_contains($whatGitSaid, 'composer.lock');
    }

    /**
     * Where composer is.
     *
     * A setting, because the web account's PATH is rarely the shell's — the
     * binary a person types as `composer` is often not on it at all, and
     * "composer: not found" from a web page is a confusing way to learn that.
     */
    private function composerBinary(): string
    {
        return (string) config('panel.composer', 'composer');
    }
}
