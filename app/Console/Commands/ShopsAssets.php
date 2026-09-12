<?php

namespace App\Console\Commands;

use App\Services\ShopAssets;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Give every shop the stylesheet the shared codebase is actually holding.
 *
 * The Updates screen does this by itself now, as part of updating the shop
 * system — see `ShopAssets` for what went wrong for months while it did not.
 * This is the same thing on the command line, for the two cases a screen cannot
 * cover: a shop system pulled by hand in a terminal, and a panel that is itself
 * too broken to press a button on.
 *
 * It is safe to run at any time and safe to run twice. A shop already wearing
 * the right build is skipped by content, not by a flag, so nothing is copied
 * that does not need to be.
 */
class ShopsAssets extends Command
{
    protected $signature = 'shops:assets
                            {--from= : The shop system’s public/build, if it is not where the .env says}
                            {--pretend : Say which shops are behind, and change none of them}';

    protected $description = 'Copy the shop system’s compiled stylesheet into every shop’s public folder';

    public function handle(ShopAssets $assets): int
    {
        $source = ($given = (string) $this->option('from')) !== '' ? rtrim($given, '/') : $assets->source();

        // Every reason at once, before anything is touched. A command that names
        // one problem, gets fixed, then names a second is a command run twice.
        if (($problems = $assets->problems($source)) !== []) {
            $this->newLine();
            $this->components->error('The shop system’s compiled stylesheet is not there to copy.');

            foreach ($problems as $problem) {
                $this->line('  <fg=red>•</> '.$problem);
            }

            $this->newLine();
            $this->line('  <fg=gray>public/build is committed in the shop system, so a missing one there is</>');
            $this->line('  <fg=gray>usually a pull that did not finish. In that folder:</>');
            $this->line('  <fg=gray>  git status && git checkout -- public/build</>');
            $this->newLine();
            $this->components->info('No shop was changed. They are all still wearing whatever they were wearing.');

            return self::FAILURE;
        }

        $behind = $assets->behind($source);

        $this->newLine();
        $this->components->twoColumnDetail('Taking from', $source);

        if ($behind === []) {
            $this->components->info('Every shop is already wearing this build. Nothing to do.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf('  <fg=yellow>%d shop(s) are behind:</>', count($behind)));

        foreach ($behind as $customer) {
            $this->line('  <fg=yellow>•</> '.$customer->name.' <fg=gray>'.$customer->public_path.'/build</>');
        }

        if ($this->option('pretend')) {
            $this->newLine();
            $this->components->info('Nothing was changed — this was a rehearsal.');
            $this->newLine();

            return self::SUCCESS;
        }

        try {
            $done = $assets->refreshEvery($source);
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($done['written'] as $folder) {
            $this->components->twoColumnDetail('Written', $folder);
        }

        foreach ($done['stubborn'] as $said) {
            $this->components->error($said);
        }

        $this->newLine();

        if ($done['stubborn'] !== []) {
            $this->components->warn(sprintf(
                '%d shop(s) updated, %d could not be. The ones that could not be are still serving their old '
                .'stylesheet, so anything new on their screens will not be styled.',
                count($done['written']),
                count($done['stubborn']),
            ));
            $this->newLine();

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d shop(s) are wearing the shared build now.',
            count($done['written']),
        ));
        $this->newLine();

        return self::SUCCESS;
    }
}
