<?php

use App\Http\Controllers\AuthenticatorController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OverviewController;
use Illuminate\Support\Facades\Route;

/*
 * PANEL_DOC Section 9 lists eight pages. Overview, Customers and One customer
 * exist — the screens that only read, build order step 5. The other five
 * arrive with steps 6 to 8. The sidebar names all of them and shows the
 * missing ones as not built yet, rather than as links that go nowhere:
 * Section 7's guard rail is that the reason is on the screen before the press,
 * not discovered after it.
 */

Route::get('/', fn () => redirect()->route('overview'));

Route::middleware('auth')->group(function () {
    Route::get('overview', [OverviewController::class, 'index'])->name('overview');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

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
