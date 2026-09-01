<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the panel did, and who told it to — PANEL_DOC Section 5.
 *
 * Mirrors `activity_logs` in the shop system, for the same reason. Anything
 * that reaches into a customer's install leaves a record with a name on it —
 * PANEL_DOC Section 1, rule 2.
 *
 * Append-only: `created_at` and nothing else. A log with an `updated_at` is a
 * log somebody can edit, and then it is not a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();

            // Nullable: signing in, changing a password and adding an operator
            // are the panel's own doing and belong to no customer. Section 9's
            // "What I changed" shows both.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Kept when the operator is deleted. "Who told it to" is the whole
            // point of the row, and a removed account must not quietly empty
            // the record of what it did.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action')->index();

            // from → to. JSON because what changed differs per action, and the
            // alternative is a sentence nobody can query.
            $table->json('detail')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
