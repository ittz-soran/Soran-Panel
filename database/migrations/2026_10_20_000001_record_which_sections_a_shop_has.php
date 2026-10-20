<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's copy of which sections a shop has — PANEL_DOC Section 6b.
 *
 * The shop's own `.env` is where the answer lives; these mirror it the way
 * `storage_limit_mb` does, and are set only from what the shop said after a
 * switch, never from what was written. Comma-separated names, exactly the two
 * lines' values, so the copy reads the same as the file it copies.
 *
 * Null is "never switched from here", not "nothing off": a shop that has never
 * been touched has both lines empty, and so does one nobody has looked at yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('sections_off')->nullable()->after('storage_limit_mb');
            $table->string('sections_on')->nullable()->after('sections_off');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['sections_off', 'sections_on']);
        });
    }
};
