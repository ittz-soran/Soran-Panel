<?php

namespace App\Support;

/**
 * Deleting a folder that belongs to a shop, and nothing else.
 *
 * A recursive delete driven by a path the code built is the one operation here
 * that can do unbounded damage from an ordinary bug, so the guard lives in one
 * place rather than beside each caller. Two callers need it — rolling back a
 * half-made shop, and removing one on purpose — and a second copy of this is
 * a second chance to get the root check subtly wrong.
 */
final class ShopFolder
{
    /**
     * @param  bool  $keepTheFolderItself  empty it, but leave the folder there.
     *                                     For a public folder cPanel made as a
     *                                     subdomain's document root: removing
     *                                     it leaves the domain pointing at
     *                                     nothing, which is a worse mess than
     *                                     the one being cleaned up.
     * @return bool whether it is gone
     */
    public static function delete(string $path, bool $keepTheFolderItself = false): bool
    {
        if (! is_dir($path)) {
            return true;
        }

        /*
         * Only under a folder this panel was told to use. A bug in the path
         * building must not be able to hand a recursive delete a shorter path
         * than it meant to.
         *
         * BOTH roots, because Section 4 forced them apart: a shop's private
         * folder is outside public_html and its public folder must be inside
         * it, so they are two different trees. The first version checked only
         * the shops root, which quietly left every rolled-back shop's public
         * folder standing — a folder that looks provisioned is one somebody
         * later points a domain at, which is the exact thing rolling back is
         * for.
         */
        $roots = array_filter([
            rtrim((string) config('panel.shops.home_root'), '/'),
            rtrim((string) config('panel.shops.public_root'), '/'),
        ]);

        $real = realpath($path) ?: $path;

        $allowed = false;

        foreach ($roots as $root) {
            if (str_starts_with($real, $root.'/')) {
                $allowed = true;
            }
        }

        if (! $allowed) {
            return false;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        return $keepTheFolderItself ? true : @rmdir($path);
    }
}
