<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel operators — PANEL_DOC Section 5.
 *
 * There is no `password_reset_tokens` table and no forgotten-password email,
 * which is the one place the panel's auth is smaller than the shop system's.
 * The reason is the shop system's own: MAIL_MAILER is `log` on this hosting, so
 * the link that flow sends has never left the building and never will. A route
 * that appears to send a way back in and does not is worse than no route, so
 * the authenticator (Section 5, and the shop system's Section 8e) is the only
 * way back in — and it needs nothing delivered anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // Admin only for now; the shape allows staff later (Section 5).
            $table->string('role')->default('admin');
            $table->boolean('is_active')->default(true);

            // Section 9: English only — the panel has one reader. `theme` is
            // the one preference kept, because a screen read at night is read
            // at night whatever language it is in.
            $table->string('theme')->default('auto');

            // The way back in. Encrypted by the model, so these hold
            // ciphertext and are useless out of a database dump on their own.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
