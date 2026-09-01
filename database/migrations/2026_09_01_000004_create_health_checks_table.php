<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An hourly snapshot per shop — PANEL_DOC Section 5.
 *
 * Snapshots in a table, not columns on `customers`, for the same reason
 * `stock_movements` exists in the shop system: you want to see storage growing
 * over weeks, and a failed check must not wipe the last good reading. A row
 * where `reachable` is false and `error` is set still leaves yesterday's real
 * numbers standing beside it.
 *
 * Every count is nullable rather than zero, because a check that could not ask
 * has to be distinguishable from a shop that genuinely has no sales. Zero
 * products is a broken install; "we do not know" is a broken check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->timestamp('checked_at');
            $table->boolean('reachable')->default(false);

            // Bytes, so they are exact and the arithmetic is the panel's.
            // Big integers: a shop's backups folder passes four gigabytes
            // sooner than anyone expects, and a signed 32-bit column stops at
            // two.
            $table->unsignedBigInteger('database_bytes')->nullable();
            $table->unsignedBigInteger('backups_bytes')->nullable();
            $table->unsignedBigInteger('uploads_bytes')->nullable();

            // What their .env actually says, read back — not what `customers`
            // believes was written. The two disagreeing is the finding.
            $table->unsignedInteger('storage_limit_mb')->nullable();

            $table->unsignedSmallInteger('migrations_run')->nullable();
            $table->unsignedSmallInteger('migrations_total')->nullable();

            // Whether anybody is actually using it. A shop nobody has touched
            // for a fortnight is on Section 9's Overview.
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('users_count')->nullable();
            $table->unsignedInteger('products_count')->nullable();
            $table->unsignedInteger('sales_count')->nullable();

            // What THEIR system thinks — a cross-check against ours. The panel
            // believing a licence is live while the shop reports `expired` is
            // the failure this column exists to surface.
            $table->string('licence_state')->nullable();

            // The seventeen Section 10b assertions from PROJECT_DOC, run
            // read-only against live data.
            $table->unsignedSmallInteger('data_check_passed')->nullable();
            $table->unsignedSmallInteger('data_check_total')->nullable();

            $table->text('error')->nullable();

            // No timestamps(): `checked_at` is when this happened, and a
            // created_at beside it would be the same moment written twice.
            // Nothing ever updates a snapshot.

            // Read as a time series, newest first, always for one shop.
            $table->index(['customer_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
