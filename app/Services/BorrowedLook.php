<?php

namespace App\Services;

use App\Support\BuildFolder;

/**
 * The look the panel borrows — PANEL_DOC Section 10.
 *
 * Section 10 gives the panel no stylesheet of its own and no npm build: what it
 * wears is the shop system's compiled `public/build`, copied in. Two things
 * follow from "copied", and both have now cost something:
 *
 * **It goes stale.** Updating the shop system changes that build. The panel
 * keeps the copy it was given at deploy time, so its markup drifts away from
 * its stylesheet with nothing said. `Updater` now refreshes it as part of
 * updating the shop system, which is the only moment it can actually go stale.
 *
 * **It can go missing, and the shell way of replacing it is what loses it.**
 * Replacing the copy by hand meant `rm -rf` then `cp -r`, and that order cannot
 * survive the copy failing — an unpulled shop system, a full disk — leaving the
 * panel serving unstyled HTML with nothing on screen to say why. It happened on
 * the live panel exactly that way.
 *
 * The order that cannot lose lives in `BuildFolder`, because the shops have the
 * same problem with the same source and must not have a second copy of the
 * dangerous part. What is this class's own is *which* folders the panel wears:
 * its own `public/`, and whatever under public_html is serving it.
 */
class BorrowedLook
{
    /**
     * Where the compiled look is taken from.
     *
     * The shop system is already a setting — the panel runs `shop:provision`
     * through its artisan — and its build is beside it. So there is nothing new
     * to configure, and nothing that can be set to two different places.
     */
    public function source(): string
    {
        return BuildFolder::shopSystem();
    }

    /**
     * Why the source cannot be used, if it cannot.
     *
     * @return list<string>
     */
    public function problems(?string $source = null): array
    {
        return BuildFolder::problems($source ?? $this->source());
    }

    /**
     * Whether the panel currently has a look at all.
     *
     * The one question the Updates screen asks without being told to: a panel
     * serving unstyled HTML is obvious to look at and impossible to diagnose
     * from the inside, so the screen says which of the two copies is missing.
     */
    public function inPlace(): bool
    {
        return is_file(public_path('build/manifest.json'));
    }

    /**
     * Published folders whose copy of the build is not the one the panel names.
     *
     * **The failure that actually happened, and the one nothing could see.**
     * There are two copies of this build: the panel's own `public/build`, which
     * is where `@vite` reads the manifest, and the copy inside `public_html`,
     * which is what a browser actually fetches the files from. Refresh only the
     * first and the manifest names `app-D4KT8ci0.css` while the folder being
     * served still holds the previous hash — so nothing throws, the page renders
     * in full, every stylesheet 404s, and the panel serves its real content as
     * unstyled HTML.
     *
     * It is not the same as having no build: that raises Vite's own exception
     * and the panel answers with a page explaining itself. This one is silent,
     * and it looks from the outside like the panel is broken.
     *
     * ⚠️ A shop's copy fails differently and is NOT found here — see
     * `ShopAssets::behind()`. A shop reads its manifest from its own public
     * folder, so an old copy is internally consistent: nothing 404s, the page
     * is fully styled, and it is styled by last month's stylesheet. Silent in a
     * way this one is not.
     *
     * @return list<string>
     */
    public function stale(): array
    {
        $named = BuildFolder::filesNamedBy(public_path('build/manifest.json'));

        if ($named === []) {
            return [];
        }

        $stale = [];

        foreach ($this->published() as $folder) {
            foreach ($named as $file) {
                if (! is_file($folder.'/build/'.$file)) {
                    $stale[] = $folder;

                    break;
                }
            }
        }

        return $stale;
    }

    /**
     * The folders inside public_html that serve THIS panel.
     *
     * Read rather than guessed: `panel:public` writes an index.php naming the
     * panel's own base path absolutely, because `..` from inside public_html is
     * public_html. So a folder is this panel's if its index.php names this
     * panel. A shop's index.php names its own shop and is left alone.
     *
     * @return list<string>
     */
    public function published(): array
    {
        $root = rtrim((string) config('panel.shops.public_root'), '/');

        if ($root === '' || ! is_dir($root)) {
            return [];
        }

        $ours = base_path().'/vendor/autoload.php';
        $found = [];

        foreach ((array) glob($root.'/*/index.php') as $index) {
            if (str_contains((string) @file_get_contents($index), $ours)) {
                $found[] = dirname($index);
            }
        }

        return $found;
    }

    /**
     * Take the shop system's build into the panel, and into what the domain serves.
     *
     * Both, always. The published folder holds its own copy — `panel:public`
     * copies `build/`, it does not link it — so refreshing only the panel's own
     * `public/` fixes nothing a visitor can see.
     *
     * @return list<string> the folders written, in the order they were written
     *
     * @throws \RuntimeException before anything is touched, if the source is unusable
     */
    public function refresh(?string $source = null): array
    {
        $source = $source === null || $source === '' ? $this->source() : rtrim($source, '/');

        if (($problems = BuildFolder::problems($source)) !== []) {
            throw new \RuntimeException(implode(' ', $problems).' Nothing was changed.');
        }

        $written = [BuildFolder::replace($source, public_path('build'))];

        foreach ($this->published() as $folder) {
            $written[] = BuildFolder::replace($source, $folder.'/build');
        }

        return $written;
    }
}
