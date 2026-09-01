<?php

namespace App\Support;

/**
 * What must never reach a shop's process — and why it is its own class.
 *
 * A child process inherits its parent's environment, and Laravel exports every
 * key of its `.env` into it, where an environment variable beats the `.env`
 * file sitting beside it. So a shop's own `artisan`, run from the panel, boots
 * with the PANEL's DB_DATABASE, DB_USERNAME and DB_PASSWORD.
 *
 * That really happened, and it is recorded in PANEL_DOC Section 8. A shop
 * reported 3 of 32 migrations run and 0 of 17 assertions passing, and both
 * numbers were true readings of the panel's own database, where three of the
 * shop system's migration names also exist.
 *
 * It was read-only commands that time. This class exists as a class, rather
 * than a private method on the reader, because the panel now also RUNS things
 * in a shop — `optimize:clear`, `migrate`, `backup:run` — and the same leak
 * there points them at the panel's own tables: the customer list, the licence
 * history and the payment record. One guard, used by everything that spawns a
 * shop's process, so a new caller cannot forget it.
 */
final class ShopEnvironment
{
    /**
     * Anything shaped like framework configuration. Matched against the
     * panel's own environment, and removed from the shop's.
     */
    private const FRAMEWORK_KEYS =
        '/^(APP|DB|CACHE|SESSION|QUEUE|MAIL|LOG|REDIS|BROADCAST|FILESYSTEM|LICENCE|'
        .'STORAGE_LIMIT|BACKUP|VITE|BCRYPT|MEMCACHED|AWS|PANEL|MYSQL|MYSQLDUMP|ADMIN)_|'
        .'^(APP_KEY|FILESYSTEM_DISK|STORAGE_LIMIT_MB)$/';

    /**
     * Pass this as a Process's `env`. Symfony merges it into the inherited
     * environment, and `false` means remove.
     *
     * @return array<string, false>
     */
    public static function withoutThePanel(): array
    {
        $clear = [];

        foreach (array_keys($_ENV + $_SERVER) as $key) {
            if (is_string($key) && preg_match(self::FRAMEWORK_KEYS, $key)) {
                $clear[$key] = false;
            }
        }

        // And whatever else this particular .env names, whether or not it looks
        // like framework configuration.
        foreach (self::panelEnvKeys() as $key) {
            $clear[$key] = false;
        }

        return $clear;
    }

    /**
     * The key names in the panel's own .env, if it has one.
     *
     * A cached config means Laravel never read the file and never exported it,
     * so there is nothing to clear — but reading the names costs nothing and
     * covers the case where it did.
     *
     * @return list<string>
     */
    private static function panelEnvKeys(): array
    {
        $path = base_path('.env');

        if (! is_readable($path)) {
            return [];
        }

        $keys = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#') && str_contains($line, '=')) {
                $keys[] = trim(explode('=', $line, 2)[0]);
            }
        }

        return $keys;
    }
}
