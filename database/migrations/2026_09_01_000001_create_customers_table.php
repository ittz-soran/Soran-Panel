<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per shop sold — PANEL_DOC Section 5.
 *
 * This is the panel's own database and it never adds tables to a shop's. The
 * four path-and-database columns are how the panel finds an install on this
 * server: they are the same values `shop:provision` wrote, and the panel reads
 * a shop through them (Section 8) rather than over HTTP, so no remote-control
 * door is opened in every customer's install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            /*
             * What the licence binds to. A licence names one host and will not
             * run on another, so two LIVE customers on one host is not a clash
             * to resolve later — it is a licence that cannot be issued
             * correctly for either of them.
             *
             * It was unique here until a later migration took that off; see
             * 2026_09_06_000001 for why a removed shop has to be able to give
             * its host back, and where the rule lives now.
             */
            $table->string('host')->unique();

            // Where this install lives, as `shop:provision` made it.
            $table->string('shop_home');
            $table->string('public_path');
            $table->string('database_name');
            $table->string('database_user')->nullable();

            $table->string('status')->default('trial')->index();

            // Integer dinars, never decimal — PROJECT_DOC Section 2. There is
            // no smaller unit than the dinar in circulation, and a float here
            // is a rounding error in somebody's invoice.
            $table->unsignedInteger('monthly_fee')->default(0);

            // Mirrors what is written to their .env. The .env is the truth; this
            // is what the panel believes it wrote, and the health check compares
            // the two.
            $table->unsignedInteger('storage_limit_mb')->nullable();

            $table->string('language')->default('en');

            $table->date('started_on')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
