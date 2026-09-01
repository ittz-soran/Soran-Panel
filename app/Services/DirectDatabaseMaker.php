<?php

namespace App\Services;

use App\Contracts\DatabaseMaker;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A database made with plain SQL.
 *
 * For Soran's own machine and any host that is not cPanel. Section 4 records
 * that this account denies `CREATE DATABASE`, so on the real server it is
 * CpanelDatabaseMaker that runs — but that one cannot be exercised anywhere
 * except cPanel, and a panel that can only be developed on the live server is
 * a panel nobody dares change.
 *
 * Names are checked rather than escaped. A database name cannot be bound as a
 * parameter, so the only safe thing is to refuse anything that is not a plain
 * identifier — and the names come from `shop:provision`'s own rules anyway.
 */
class DirectDatabaseMaker implements DatabaseMaker
{
    public function realName(string $wanted): string
    {
        return $wanted;
    }

    public function create(string $database, string $user, string $password): void
    {
        $this->refuseOddNames($database, $user);

        // Never over the top of one that exists. A shop pointed at somebody
        // else's database reads their customers, and a CREATE that quietly
        // succeeded against an existing schema is how that happens.
        $already = DB::connection($this->connection())->select(
            'select schema_name from information_schema.schemata where schema_name = ?', [$database],
        );

        if ($already !== []) {
            throw new RuntimeException("A database called [{$database}] already exists. Nothing was created.");
        }

        $connection = DB::connection($this->connection());

        $connection->statement("create database `{$database}` character set utf8mb4 collate utf8mb4_unicode_ci");

        try {
            /*
             * The password goes in the statement, not a binding.
             *
             * MariaDB will not accept a placeholder in CREATE USER … IDENTIFIED
             * BY — it is parsed as part of the grammar rather than as a value —
             * so there is nothing to bind to. What makes that safe here is that
             * the password is not user input at all: ShopProvisioner generates
             * it with Str::random, and this refuses anything that is not
             * letters and numbers rather than trusting that.
             */
            if (! preg_match('/^[A-Za-z0-9]{12,}$/', $password)) {
                throw new RuntimeException('That password is not one this will put into a statement.');
            }

            $connection->statement("create user if not exists `{$user}`@`%` identified by '{$password}'");
            $connection->statement("grant all privileges on `{$database}`.* to `{$user}`@`%`");
            $connection->statement('flush privileges');
        } catch (Throwable $e) {
            // The database exists and its user does not, which is a shop that
            // cannot connect. Take the database back rather than leave that.
            $this->drop($database, $user);

            throw new RuntimeException('Could not make the database user: '.$e->getMessage());
        }
    }

    public function drop(string $database, string $user): array
    {
        $left = [];

        foreach ([
            "drop database if exists `{$database}`" => "the database [{$database}]",
            "drop user if exists `{$user}`@`%`" => "the database user [{$user}]",
        ] as $sql => $what) {
            try {
                DB::connection($this->connection())->statement($sql);
            } catch (Throwable) {
                $left[] = $what;
            }
        }

        return $left;
    }

    /**
     * The connection with rights to create databases — not the panel's own.
     *
     * The panel's own database user deliberately has rights over one schema,
     * so making shops needs a separate, more powerful account named in the
     * panel's .env and used nowhere else.
     */
    private function connection(): string
    {
        return config('panel.database_maker.connection', 'mysql');
    }

    private function refuseOddNames(string ...$names): void
    {
        foreach ($names as $name) {
            if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
                throw new RuntimeException(
                    "[{$name}] is not a name this will create. Letters, numbers and underscores only.",
                );
            }
        }
    }
}
