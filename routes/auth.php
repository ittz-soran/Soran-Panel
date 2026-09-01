<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RecoverPasswordController;
use Illuminate\Support\Facades\Route;

/*
 * There is no /register route and there never will be. The panel reaches into
 * every customer's install; an account on it is not something anybody creates
 * for themselves. Operators are seeded, and later added by an admin.
 *
 * There is no forgotten-password email either — see the users migration. The
 * way back in is the authenticator, and it needs nothing delivered anywhere.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('recover-password', [RecoverPasswordController::class, 'show'])
        ->name('password.recover');
    Route::post('recover-password', [RecoverPasswordController::class, 'update'])
        ->name('password.recover.update');
});

Route::middleware('auth')->group(function () {
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
