<?php

namespace App\Services;

use App\Contracts\DatabaseMaker;
use App\Support\Uapi;
use RuntimeException;
use Throwable;

/**
 * A database made through cPanel's UAPI — PANEL_DOC Section 4.
 *
 * "Plain SQL `CREATE DATABASE` denied, which is normal on cPanel. Use UAPI."
 * Section 4 measured `/usr/bin/uapi` answering on this account, so this is the
 * one that runs on the real server.
 *
 * **Proved on the real account 2026-09-05**, by creating a shop through New
 * customer: `create_database`, `create_user` and `set_privileges_on_database`
 * all answered, and the shop was provisioned, migrated and seeded from them.
 * Until then this was written from cPanel's documented UAPI and Section 4's
 * measurement, with its tests driving a fake.
 *
 * Two cPanel facts the rest of the panel must not have to know:
 *
 *   - Every database and user is prefixed with the account name, so a database
 *     asked for as `bazaar_shop` is really `soransto_bazaar_shop`. What the
 *     customer row records has to be the real one, or the panel cannot read
 *     that shop again.
 *   - Names are limited to 64 characters INCLUDING that prefix.
 */
class CpanelDatabaseMaker implements DatabaseMaker
{
    public function __construct(private readonly Uapi $uapi = new Uapi) {}

    public function realName(string $wanted): string
    {
        $prefix = (string) config('panel.cpanel.prefix', '');

        if ($prefix === '' || str_starts_with($wanted, $prefix.'_')) {
            return $wanted;
        }

        return $prefix.'_'.$wanted;
    }

    public function create(string $database, string $user, string $password): void
    {
        $database = $this->realName($database);
        $user = $this->realName($user);

        foreach ([$database, $user] as $name) {
            if (mb_strlen($name) > 64) {
                throw new RuntimeException(
                    "[{$name}] is ".mb_strlen($name).' characters. cPanel allows 64, including the account '
                    .'prefix. Give the shop a shorter short name.',
                );
            }
        }

        $this->uapi->call('Mysql', 'create_database', ['name' => $database]);

        try {
            $this->uapi->call('Mysql', 'create_user', ['name' => $user, 'password' => $password]);
            $this->uapi->call('Mysql', 'set_privileges_on_database', [
                'user' => $user,
                'database' => $database,
                'privileges' => 'ALL PRIVILEGES',
            ]);
        } catch (Throwable $e) {
            // A database with no user that can reach it is a shop that cannot
            // start. Take it back rather than leave that lying around.
            $this->drop($database, $user);

            throw $e;
        }
    }

    public function drop(string $database, string $user): array
    {
        $left = [];

        foreach ([
            ['Mysql', 'delete_database', ['name' => $this->realName($database)], "the database [{$database}]"],
            ['Mysql', 'delete_user', ['name' => $this->realName($user)], "the database user [{$user}]"],
        ] as [$module, $call, $arguments, $what]) {
            try {
                $this->uapi->call($module, $call, $arguments);
            } catch (Throwable) {
                $left[] = $what;
            }
        }

        return $left;
    }

    /**
     * One UAPI call, with cPanel's own error text kept.
     *
     * @param  array<string, string>  $arguments
     * @return array<string, mixed>
     */
}
