<?php

namespace App\Support;

/**
 * Bytes, as a person reads them.
 *
 * The panel stores exact bytes everywhere — PANEL_DOC Section 5 — so that the
 * arithmetic is the panel's and not the shop's. This is the one place it turns
 * back into something to put on a screen, so a storage figure means the same
 * thing on the Overview, on the customer list and in a terminal.
 */
final class Bytes
{
    public static function human(?int $bytes): string
    {
        if ($bytes === null) {
            // Not "0 B". A figure nobody could read is not a shop using
            // nothing — PANEL_DOC Section 5.
            return 'unknown';
        }

        $size = (float) $bytes;

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($size < 1024 || $unit === 'TB') {
                // Whole numbers past a gigabyte read better than 1.0 GB, and
                // one decimal below that keeps 1.4 MB from becoming 1 MB.
                return ($unit === 'B' ? (int) $size : round($size, $size >= 100 ? 0 : 1)).' '.$unit;
            }

            $size /= 1024;
        }

        return $bytes.' B';
    }
}
