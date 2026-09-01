<?php

use App\Http\Controllers\AuthenticatorController;
use App\Http\Controllers\OverviewController;
use Illuminate\Support\Facades\Route;

/*
 * PANEL_DOC Section 9 lists eight pages. Only Overview exists so far — the
 * other seven arrive with build order steps 5 to 8. The sidebar names all
 * eight and shows the missing ones as not built yet, rather than as links that
 * go nowhere: Section 7's guard rail is that the reason is on the screen
 * before the press, not discovered after it.
 */

Route::get('/', fn () => redirect()->route('overview'));

Route::middleware('auth')->group(function () {
    Route::get('overview', [OverviewController::class, 'index'])->name('overview');

    Route::get('profile/authenticator', [AuthenticatorController::class, 'show'])
        ->name('authenticator.show');
    Route::post('profile/authenticator', [AuthenticatorController::class, 'confirm'])
        ->name('authenticator.confirm');
    Route::post('profile/authenticator/codes', [AuthenticatorController::class, 'regenerate'])
        ->name('authenticator.codes');
    Route::delete('profile/authenticator', [AuthenticatorController::class, 'destroy'])
        ->name('authenticator.destroy');
});

require __DIR__.'/auth.php';
