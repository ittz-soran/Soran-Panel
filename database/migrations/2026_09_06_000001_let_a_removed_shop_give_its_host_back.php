<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `customers.host` stops being unique in the schema.
 *
 * The original index was right for the panel as it then was: a licence names
 * one host and will not run on another, so two customers on one host is not a
 * clash to resolve later, it is a licence that cannot be issued correctly for
 * either of them. That is still true — of two LIVE customers.
 *
 * `ShopRemover` made the distinction matter. A removed shop's row is kept, soft
 * deleted, because Section 5 says the licence history and the payments outlive
 * the customer; but the shop itself is gone — folders, subdomain, DNS record
 * and database. Its host is free, and rebuilding a shop under the name it
 * traded as is most of what removing one is for. A plain UNIQUE index cannot
 * tell those apart: it counts the removed row and refuses for ever.
 *
 * ⚠️ **The obvious fixes do not work on these two engines.** A composite
 * unique on `(host, deleted_at)` looks right and is not — MySQL, MariaDB and
 * SQLite all treat NULLs in a unique index as distinct, so it would let TWO
 * LIVE customers share a host, which is worse than what it replaced. A partial
 * unique index — the exact right tool, `where deleted_at is null` — exists in
 * SQLite and not in MariaDB, and the panel must run identically on both.
 *
 * So the guarantee moves up a level, where it was already stated twice: the
 * `host` rule in NewCustomerController is `Rule::unique(...)->withoutTrashed()`,
 * and `ShopProvisioner::refuseIfAnythingIsInTheWay` refuses on a live customer
 * — plus on the folders, which is the check that catches a removal that only
 * partly worked. Both are covered by tests that name this.
 *
 * The index stays, without the uniqueness: every screen looks a customer up by
 * host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['host']);
            $table->index('host');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['host']);
            $table->unique('host');
        });
    }
};
