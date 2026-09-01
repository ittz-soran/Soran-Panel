<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every licence ever issued — PANEL_DOC Section 5.
 *
 * A renewal is a new row, never an edit. That is what makes the licence history
 * on a customer's page possible, and the only way to answer "when did this shop
 * last actually pay" months later.
 *
 * `delivered_at` is set from a confirmation, never from an assumption: Section
 * 6 step 7 asks the shop what it now thinks and shows the answer back. A
 * licence written into a .env that the shop never picked up is a customer who
 * paid and is still locked out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // The reference `licence:issue` printed — K7QP-3MZX. Not unique by
            // the database's hand: it is four random characters twice, made on
            // Soran's machine, and a collision should be caught and reported
            // rather than becoming an insert that fails at 2am.
            $table->string('licence_id')->index();

            // Copied out of the signed payload rather than from the customer
            // row, so the history says what each licence actually bound to even
            // after a shop moves domain.
            $table->string('host')->nullable();

            // `licence_key`, not `key`. Section 5 writes it as `key`, and KEY is
            // a reserved word in MariaDB — the exact class of bug Section 1
            // records this project having already shipped once ("a MariaDB
            // reserved word that SQLite accepted"). Laravel quotes its own
            // identifiers so `key` survives the query builder, but it breaks the
            // moment anything reaches for raw SQL, and a licence column is one
            // people will read with mysql client in hand.
            $table->text('licence_key');

            $table->date('issued_on');

            // Null means sold outright, with no end date.
            $table->date('expires_on')->nullable()->index();

            $table->timestamp('delivered_at')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->timestamps();

            // "What is this shop running now" and "what runs out next" are the
            // two questions every screen in Section 9 asks.
            $table->index(['customer_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licences');
    }
};
