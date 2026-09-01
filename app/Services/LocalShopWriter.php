<?php

namespace App\Services;

use App\Contracts\ShopWriter;
use App\Models\Customer;
use App\Support\ShopEnvironment;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Changing a shop that lives on this same server.
 *
 * The whole file is rewritten every time, and the way it is rewritten is the
 * careful part. A shop's `.env` holds its database password and its APP_KEY: a
 * half-written one is not a shop with a wrong setting, it is a shop that does
 * not start, and its sessions and any encrypted column are unreadable for ever
 * if APP_KEY is what got lost.
 *
 * So: the old file is copied to `.env.bak` first (PANEL_DOC Section 6, step 5),
 * the new one is written to a temporary file beside it and renamed over the
 * top, and the rename is what makes it visible. A rename within one directory
 * is atomic, so a shop being served in that instant reads either the whole old
 * file or the whole new one, and never half of either.
 */
class LocalShopWriter implements ShopWriter
{
    private const TIMEOUT = 60;

    public function putEnv(Customer $customer, array $set, array $remove = [], array $removeIfBlank = []): void
    {
        $path = rtrim($customer->shop_home, '/').'/.env';

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("This shop has no readable .env at [{$path}], so nothing was changed.");
        }

        $original = file_get_contents($path);

        if ($original === false) {
            throw new RuntimeException("This shop's .env at [{$path}] could not be read, so nothing was changed.");
        }

        $rewritten = $this->rewrite($original, $set, $remove, $removeIfBlank);

        // The backup first, and from the bytes we actually read — not a second
        // read of a file that may have changed under us.
        if (file_put_contents($path.'.bak', $original, LOCK_EX) === false) {
            throw new RuntimeException("Could not write [{$path}.bak], so nothing was changed.");
        }

        /*
         * And with the original's permissions, not the umask's.
         *
         * file_put_contents creates at 0644. The file it just created is a
         * complete copy of a shop's .env — its database password, its APP_KEY —
         * so a backup written world-readable beside a 0600 original hands over
         * everything the original was protecting. PANEL_DOC Section 4 records
         * finding exactly this on Halabja-phone's install; it would have been
         * embarrassing to recreate it here, and it was, until the permissions
         * were looked at on a real shop rather than assumed.
         */
        @chmod($path.'.bak', fileperms($path) & 0777);

        // Same directory, so the rename below stays on one filesystem and is
        // therefore atomic. A temp file in /tmp would make it a copy.
        $temporary = $path.'.panel-'.bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $rewritten, LOCK_EX) === false) {
            @unlink($temporary);

            throw new RuntimeException("Could not write a new .env beside [{$path}], so nothing was changed.");
        }

        // Whatever the shop's own file was set to — its owner is the hosting
        // account, and a .env that becomes world-readable is the same finding
        // Section 4 recorded against Halabja-phone.
        @chmod($temporary, fileperms($path) & 0777);

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Could not replace [{$path}]. The shop is untouched.");
        }
    }

    public function clearCache(Customer $customer): bool
    {
        $artisan = rtrim($customer->shop_home, '/').'/artisan';

        if (! is_file($artisan)) {
            return false;
        }

        // `optimize:clear` rather than `config:clear`: a shop caches its routes
        // and views too, and Section 3 gives each shop its own bootstrap/cache.
        $process = new Process(
            [PHP_BINARY, $artisan, 'optimize:clear'],
            env: ShopEnvironment::withoutThePanel(),
        );
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * The new file, line by line.
     *
     * Rewritten in place rather than regenerated, so comments, blank lines and
     * the order a person put things in all survive. A shop's `.env` is a file
     * somebody may have to read at three in the morning.
     *
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     * @param  list<string>  $removeIfBlank
     */
    private function rewrite(string $original, array $set, array $remove, array $removeIfBlank = []): string
    {
        $remaining = $set;
        $lines = preg_split('/\R/', $original) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $name = $this->keyOn($line);

            if ($name !== null && in_array($name, $remove, true)) {
                continue;
            }

            /*
             * Dropped only when it is set to nothing.
             *
             * PANEL_DOC Section 6: ending a trial must REMOVE the blank
             * LICENCE_PUBLIC_KEY, not set it to something — an empty value is
             * what switches licensing off, so leaving the line there means the
             * new licence is never checked at all, which is the same as no
             * licence.
             *
             * "The blank line", though, and not the key. A shop with a public
             * key set on purpose is saying something deliberate, and deleting
             * it would quietly move that shop onto the committed default —
             * which is invisible right up until the day the two differ.
             */
            if ($name !== null && in_array($name, $removeIfBlank, true)) {
                if (trim(explode('=', ltrim($line), 2)[1] ?? '') === '') {
                    continue;
                }
            }

            if ($name !== null && array_key_exists($name, $remaining)) {
                $out[] = $name.'='.$this->quote($remaining[$name]);
                unset($remaining[$name]);

                continue;
            }

            $out[] = $line;
        }

        // Anything that was not already in the file goes on the end.
        foreach ($remaining as $name => $value) {
            $out[] = $name.'='.$this->quote($value);
        }

        $text = implode("\n", $out);

        return rtrim($text, "\n")."\n";
    }

    /** The key a line sets, or null if it sets nothing (a comment, a blank). */
    private function keyOn(string $line): ?string
    {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
            return null;
        }

        $name = trim(explode('=', $trimmed, 2)[0]);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : null;
    }

    /**
     * Quoted only when it has to be.
     *
     * A licence string is base64url with a dot — safe bare. A shop name is
     * "Hawler Computer", with a space, and unquoted that is read as "Hawler".
     */
    private function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return preg_match('/[\s"\'#]/', $value)
            ? '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"'
            : $value;
    }
}
