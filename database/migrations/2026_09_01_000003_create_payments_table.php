<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money received — PANEL_DOC Section 5.
 *
 * Two date pairs, not one. `paid_on` is when the money arrived; `covers_from`
 * and `covers_to` are which period it buys. A customer who pays three months at
 * once is then not chased next week, and a late payment still starts from the
 * day the last licence ended rather than losing them the days they were late.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Integer dinars, never decimal — PROJECT_DOC Section 2.
            $table->unsignedInteger('amount');

            $table->date('paid_on')->index();

            $table->date('covers_from');
            $table->date('covers_to');

            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // "Who is paid up to when" — the Subscriptions screen's only real
            // question, asked per customer against a date.
            $table->index(['customer_id', 'covers_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
