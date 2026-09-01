<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against a database that is not a test database.
     *
     * This is here because it happened. Running the suite with `--env=testing`
     * and no .env.testing file made Laravel fall back to .env — so phpunit.xml's
     * sqlite settings were overridden by the real ones, and RefreshDatabase
     * dropped every table in the panel's development database. On a laptop that
     * costs a re-seed. On the server it would be the customer list, the licence
     * history and the payment record, which PANEL_DOC Section 13 calls worse to
     * lose than any one shop.
     *
     * A test database is sqlite, or a name ending `_test`. Anything else stops
     * the suite before the first table is dropped.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        if ($driver === 'sqlite' || str_ends_with($database, '_test')) {
            return;
        }

        // Put it back the way it was found before throwing, so the failure is
        // the message below rather than a half-migrated database.
        DB::disconnect();

        throw new RuntimeException(
            "The test suite refuses to run against [{$database}] on the [{$connection}] connection: "
            .'it is not a test database, and the suite drops every table it touches. '
            .'Use sqlite, or a database whose name ends in "_test".'
        );
    }
}
