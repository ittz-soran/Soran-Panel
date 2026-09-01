<?php

namespace App\Services;

use App\Contracts\DatabaseMaker;
use App\Support\ShopEnvironment;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A database made through cPanel's UAPI — PANEL_DOC Section 4.
 *
 * "Plain SQL `CREATE DATABASE` denied, which is normal on cPanel. Use UAPI."
 * Section 4 measured `/usr/bin/uapi` answering on this account, so this is the
 * one that runs on the real server.
 *
 * ⚠️ This has not been run against a real cPanel account. Everything here is
 * written from cPanel's documented UAPI and Section 4's measurement, and its
 * tests drive a fake `uapi` that answers the way cPanel documents. The first
 * real customer created through the panel is the thing that proves it, and the
 * shape of the failure to expect is a call name or a parameter name being
 * wrong — which shows up as cPanel's own error text on the screen rather than
 * as something silent.
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
    private const TIMEOUT = 60;

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

        $this->uapi('Mysql', 'create_database', ['name' => $database]);

        try {
            $this->uapi('Mysql', 'create_user', ['name' => $user, 'password' => $password]);
            $this->uapi('Mysql', 'set_privileges_on_database', [
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
                $this->uapi($module, $call, $arguments);
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
    private function uapi(string $module, string $call, array $arguments): array
    {
        $command = [config('panel.cpanel.uapi', '/usr/bin/uapi'), '--output=json', $module, $call];

        foreach ($arguments as $name => $value) {
            $command[] = "{$name}={$value}";
        }

        $process = new Process($command, env: ShopEnvironment::withoutThePanel());
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'cPanel did not answer %s::%s in JSON. It said: %s',
                $module, $call,
                mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'nothing at all', 0, 300),
            ));
        }

        // UAPI reports failure inside a successful process. `errors` is null
        // when all is well and an array of sentences when it is not, and those
        // sentences are cPanel's own — far more use on a screen than anything
        // this could invent.
        $errors = data_get($decoded, 'result.errors');

        if (! empty($errors)) {
            throw new RuntimeException(sprintf(
                'cPanel refused %s::%s — %s', $module, $call, implode(' ', (array) $errors),
            ));
        }

        if ((int) data_get($decoded, 'result.status', 0) !== 1) {
            throw new RuntimeException("cPanel did not carry out {$module}::{$call}, and gave no reason.");
        }

        return $decoded;
    }
}
