<?php

namespace App\Services;

use App\Contracts\ShopWriter;
use App\Models\Action;
use App\Models\Customer;
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

            if ($state['ok'] && $askGithub) {
                try {
                    $checkout->fetch();
                    $waiting = $checkout->waiting();
                    $asked = true;
                } catch (RuntimeException $e) {
                    $problem = $e->getMessage();
                }
            }

            $seen[$key] = [
                ...$state,
                'problem' => $problem,
                'name' => $checkout->name,
                'path' => $checkout->path,
                'waiting' => $waiting,
                'asked' => $asked,
            ];
        }

        return $seen;
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
        $writer = app(ShopWriter::class);
        $stubborn = [];

        foreach (Customer::all() as $customer) {
            if (! $writer->clearCache($customer)) {
                $stubborn[] = $customer->name;
            }
        }

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
