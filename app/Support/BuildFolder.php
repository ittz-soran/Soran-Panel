<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A compiled `public/build`, and the only safe way to put a new one where an
 * old one is standing.
 *
 * The shop system compiles its stylesheet once and every install wears a COPY
 * of it — the panel (Section 10), the folder the panel's domain points at, and
 * each shop's public folder, which `shop:provision` fills with `copyTree`. Four
 * kinds of destination, one source, and exactly one thing that can go wrong in
 * all of them: replacing a copy means removing what is there and putting
 * something else in its place, and the shell way round — `rm -rf` then `cp -r`
 * — cannot survive the copy failing. It leaves the site serving unstyled HTML
 * with nothing on screen to say why. That happened to the live panel.
 *
 * So the mechanics live here, once, rather than beside each caller: read the
 * source and refuse if it is not a finished build, copy it in beside the old
 * one under another name, and only then swap the two with a rename. A failure
 * before the swap leaves the destination exactly as it was, still styled.
 *
 * `BorrowedLook` uses it for the panel's own copies and `ShopAssets` for the
 * shops'. A second implementation of this is a second chance to get the order
 * subtly wrong.
 */
final class BuildFolder
{
    /**
     * Where every copy is taken from.
     *
     * The shop system is already a setting — the panel runs `shop:provision`
     * through its artisan — and its build is beside it. So there is nothing new
     * to configure, and nothing that can be set to two different places.
     */
    public static function shopSystem(): string
    {
        return dirname((string) config('panel.shops.shared_artisan')).'/public/build';
    }

    /**
     * Why a source cannot be copied, if it cannot.
     *
     * A folder of the right name is not a build. The copy that broke the live
     * panel would have passed an `is_dir` check and failed everything after it.
     *
     * @return list<string>
     */
    public static function problems(string $source): array
    {
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
     * Is what is at `$target` the same build as what is at `$source`?
     *
     * The manifest names every compiled file with its content hash in it, so
     * two identical manifests mean two identical builds. Compared as a file
     * rather than parsed, because that is the whole question and the shop
     * system's own `shop:update` asks it exactly this way — one answer, in two
     * places, that cannot drift.
     */
    public static function matches(string $source, string $target): bool
    {
        $theirs = $target.'/manifest.json';
        $ours = $source.'/manifest.json';

        return is_file($theirs)
            && is_file($ours)
            && hash_file('sha256', $theirs) === hash_file('sha256', $ours);
    }

    /**
     * Put a copy of `$source` at `$target`, without ever leaving nothing there.
     *
     * Copy in beside the old one, check the copy arrived, then two renames — so
     * the old build is only removed once the new one is on the disk and whole.
     * The only moment there is no build is between those two renames.
     *
     * @throws RuntimeException with nothing changed, in every case but the last
     */
    public static function replace(string $source, string $target): string
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
            // back rather than leaving the site with no look at all.
            if (is_dir($previous)) {
                @rename($previous, $target);
            }

            File::deleteDirectory($staging);

            throw new RuntimeException("Could not put the new build at [{$target}]. The old one is still there.");
        }

        File::deleteDirectory($previous);

        return $target;
    }

    /**
     * Every file a Vite manifest names — the entries and the stylesheets they pull.
     *
     * @return list<string>
     */
    public static function filesNamedBy(string $manifest): array
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
}
