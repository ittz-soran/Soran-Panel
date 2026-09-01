<?php

use App\Http\Controllers\AuthenticatorController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\LicenceController;
use App\Http\Controllers\OperatorController;
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

    // Renew — Section 6. The panel shows a command and takes a paste; it never
    // signs anything, because the private key never reaches this server.
    Route::get('customers/{customer}/renew', [LicenceController::class, 'create'])->name('customers.renew');
    Route::post('customers/{customer}/renew', [LicenceController::class, 'store'])->name('customers.renew.store');

    /*
     * Who may sign in. Not under a customer, because an operator belongs to the
     * panel: PANEL_DOC Section 5 keeps `actions` with a nullable customer_id
     * for exactly this — adding an operator is the panel's own doing.
     */
    Route::get('operators', [OperatorController::class, 'index'])->name('operators.index');
    Route::get('operators/new', [OperatorController::class, 'create'])->name('operators.create');
    Route::post('operators', [OperatorController::class, 'store'])->name('operators.store');
    Route::get('operators/{operator}', [OperatorController::class, 'edit'])->name('operators.edit');
    Route::put('operators/{operator}', [OperatorController::class, 'update'])->name('operators.update');
    Route::post('operators/{operator}/active', [OperatorController::class, 'deactivate'])->name('operators.deactivate');
    Route::post('operators/{operator}/authenticator', [OperatorController::class, 'resetAuthenticator'])->name('operators.authenticator');
    Route::delete('operators/{operator}', [OperatorController::class, 'destroy'])->name('operators.destroy');
    Route::post('operators/{operator}/restore', [OperatorController::class, 'restore'])->name('operators.restore');

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
