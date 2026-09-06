<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\AuthenticatorController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LicenceController;
use App\Http\Controllers\NewCustomerController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\RemoveShopController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TakeOnController;
use App\Http\Controllers\UpdateController;
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

    // Step 7. Before {customer}, or `new` is read as a customer's id.
    Route::get('customers/new', [NewCustomerController::class, 'create'])->name('customers.create');
    Route::post('customers/new', [NewCustomerController::class, 'store'])->name('customers.store');

    // Step 10, and the same reason for sitting above {customer}. A shop whose
    // database is already there — Halabja-phone, whose folder was deleted and
    // whose database was deliberately kept.
    Route::get('customers/take-on', [TakeOnController::class, 'create'])->name('customers.take-on');
    Route::post('customers/take-on', [TakeOnController::class, 'store'])->name('customers.take-on.store');
    // withTrashed, and only here. A removed shop's licence history and its
    // payments outlive it (Section 5), so its page has to stay readable at the
    // same address. Every route that CHANGES a shop is left without it, so a
    // removed one 404s rather than being suspended, renewed or removed twice.
    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->withTrashed()->name('customers.show');

    // Renew — Section 6. The panel shows a command and takes a paste; it never
    // signs anything, because the private key never reaches this server.
    Route::get('customers/{customer}/renew', [LicenceController::class, 'create'])->name('customers.renew');
    Route::post('customers/{customer}/renew', [LicenceController::class, 'store'])->name('customers.renew.store');

    // What a licence would say, for the browser to sign on Soran's own machine.
    // The panel builds it; the key never comes here.
    Route::post('customers/{customer}/renew/payload', [LicenceController::class, 'payload'])
        ->name('customers.renew.payload');

    // Section 7's controls. All of them write the shop's .env, ask the shop
    // what it now thinks, and leave a record with a name on it.
    Route::post('customers/{customer}/storage', [CustomerController::class, 'storageLimit'])->name('customers.storage');
    Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
    Route::post('customers/{customer}/resume', [CustomerController::class, 'resume'])->name('customers.resume');

    // Section 3's other half: one codebase updated once leaves every shop's
    // database behind until somebody runs its migrations.
    Route::post('customers/{customer}/migrate', [CustomerController::class, 'migrate'])
        ->name('customers.migrate');

    // The one thing here that cannot be undone. Its rules are in ShopRemover.
    Route::delete('customers/{customer}', [RemoveShopController::class, 'destroy'])->name('customers.remove');

    /*
     * What version the code is on, and taking the next one from GitHub.
     * Section 3's one-codebase-many-shops only pays off if updating is
     * something that actually gets done.
     */
    Route::get('updates', [UpdateController::class, 'index'])->name('updates');
    Route::post('updates', [UpdateController::class, 'store'])->name('updates.store');

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

    // The shops' own reports, and the record of what the panel did. Both
    // read-only: Section 8's data check reports and never repairs, and a log
    // somebody can edit is not a log.
    Route::get('health', [HealthController::class, 'index'])->name('health.index');

    /*
     * The panel's own backup — Section 13. Above {customer}, or `backup` is
     * read as a customer, which is the same trap as `customers/new`.
     */
    Route::post('health/backup', [HealthController::class, 'backUp'])->name('health.backup');
    Route::get('health/backup/{kind}/{name}', [HealthController::class, 'downloadBackup'])
        ->name('health.backup.download');

    Route::post('health/{customer}', [HealthController::class, 'recheck'])->name('health.recheck');

    Route::get('changes', [ActionController::class, 'index'])->name('actions.index');

    /*
     * Money — Section 9. Deliberately its own section rather than part of a
     * customer: the question "who has paid" is asked across everybody, and a
     * licence never answers it.
     */
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('subscriptions/{customer}', [SubscriptionController::class, 'show'])->name('subscriptions.show');
    Route::post('subscriptions/{customer}', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::put('subscriptions/{customer}/{payment}', [SubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::delete('subscriptions/{customer}/{payment}', [SubscriptionController::class, 'destroy'])->name('subscriptions.destroy');
    Route::post('subscriptions/{customer}/{payment}/restore', [SubscriptionController::class, 'restore'])->name('subscriptions.restore');

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
