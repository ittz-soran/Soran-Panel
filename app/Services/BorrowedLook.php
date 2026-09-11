<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

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
 * So `refresh()` does the same job in the order that cannot lose: read the
 * source and refuse if it is not a finished build, copy it in beside the old one
 * under another name, and only then swap the two with a rename. A failure before
 * the swap leaves the panel exactly as it was, still styled.
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
        return dirname((string) config('panel.shops.shared_artisan')).'/public/build';
    }

    /**
     * Why the source cannot be used, if it cannot.
     *
     * A folder of the right name is not a build. The copy that broke the live
     * panel would have passed an `is_dir` check and failed everything after it.
     *
     * @return list<string>
     */
    public function problems(?string $source = null): array
    {
        $source = $source ?? $this->source();

        if (! is_dir($source)) {
            return ["There is no [{$source}]."];
        }

        $problems = [];

        if (! is_file($source.'/manifest.json')) {
            $problems[] = "[{$source}] has no manifest.json, so it is a folder rather than a finished build.";
        }

        if (glob($source.'/assets/*.css') === []) {
            $problems[] = "[{$source}] has no stylesheet in assets/, so copying it would change nothing.";
        }

        return $problems;
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
     * @return list<string>
     */
    public function stale(): array
    {
        $named = $this->filesNamedBy(public_path('build/manifest.json'));

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
     * Every file a Vite manifest names — the entries and the stylesheets they pull.
     *
     * @return list<string>
     */
    private function filesNamedBy(string $manifest): array
    {
        if (! is_file($manifest)) {
            return [];
        }

        $read = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($read)) {
            return [];
        }

        $files = [];

        foreach ($read as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (isset($entry['file']) && is_string($entry['file'])) {
                $files[] = $entry['file'];
            }

            foreach ((array) ($entry['css'] ?? []) as $css) {
                if (is_string($css)) {
                    $files[] = $css;
                }
            }
        }

        return array_values(array_unique($files));
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
     * @throws RuntimeException before anything is touched, if the source is unusable
     */
    public function refresh(?string $source = null): array
    {
        $source = $source === null || $source === '' ? $this->source() : rtrim($source, '/');

        if (($problems = $this->problems($source)) !== []) {
            throw new RuntimeException(implode(' ', $problems).' Nothing was changed.');
        }

        $written = [$this->replace($source, public_path('build'))];

        foreach ($this->published() as $folder) {
            $written[] = $this->replace($source, $folder.'/build');
        }

        return $written;
    }

    /**
     * Put a copy of `$source` at `$target`, without ever leaving nothing there.
     *
     * Copy in beside the old one, check the copy arrived, then two renames — so
     * the old build is only removed once the new one is on the disk and whole.
     * The only moment the panel has no build is between those two renames.
     */
    private function replace(string $source, string $target): string
    {
        $staging = $target.'.incoming';
        $previous = $target.'.previous';

        // Leavings from a run that died half way. Deleting these is safe in a
        // way deleting $target is not: nothing serves them.
        File::deleteDirectory($staging);
        File::deleteDirectory($previous);

        if (! File::copyDirectory($source, $staging)) {
            File::deleteDirectory($staging);

            throw new RuntimeException("Could not copy the build into [{$staging}]. Nothing was changed.");
        }

        if (! is_file($staging.'/manifest.json')) {
            File::deleteDirectory($staging);

            throw new RuntimeException(
                "The copy at [{$staging}] arrived without its manifest.json. Nothing was changed.",
            );
        }

        if (is_dir($target) && ! @rename($target, $previous)) {
            File::deleteDirectory($staging);

            throw new RuntimeException("Could not move the old build at [{$target}] aside. Nothing was changed.");
        }

        if (! @rename($staging, $target)) {
            // The one path where something has already been taken away. Put it
            // back rather than leaving the panel with no look at all.
            if (is_dir($previous)) {
                @rename($previous, $target);
            }

            File::deleteDirectory($staging);

            throw new RuntimeException("Could not put the new build at [{$target}]. The old one is still there.");
        }

        File::deleteDirectory($previous);

        return $target;
    }
}
