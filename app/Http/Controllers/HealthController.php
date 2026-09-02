<?php

namespace App\Http\Controllers;

use App\Contracts\ShopReader;
use App\Models\Customer;
use App\Models\HealthCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Each shop's own report on itself, read hourly — PANEL_DOC Section 9.
 *
 * Not the panel's opinion of a shop: the shop's own answers, gathered by
 * `shops:check` through the shop's own `artisan` (Section 8). What the screen
 * adds is the comparison — every shop side by side, so a problem common to all
 * of them looks different from a problem with one.
 *
 * Nothing here writes. Section 8's data check is read-only "deliberately: a
 * contradiction is evidence, and repairing it before it has been read destroys
 * the record of what went wrong", and this screen keeps that promise by having
 * nothing to press except "look again".
 */
class HealthController extends Controller
{
    public function index(): View
    {
        $customers = Customer::query()
            ->with(['latestHealthCheck', 'lastGoodHealthCheck'])
            ->orderBy('name')
            ->get();

        $live = $customers->filter(fn (Customer $customer) => $customer->isLive());

        return view('health.index', [
            'customers' => $customers,
            'lastRun' => HealthCheck::max('checked_at'),
            'counts' => [
                'live' => $live->count(),
                'unreachable' => $live->filter(
                    fn (Customer $c) => $c->latestHealthCheck && ! $c->latestHealthCheck->reachable,
                )->count(),
                'never' => $live->filter(fn (Customer $c) => $c->latestHealthCheck === null)->count(),
                'contradicting' => $live->filter(
                    fn (Customer $c) => $c->lastGoodHealthCheck?->dataCheckPassed() === false,
                )->count(),
                'behind' => $live->filter(
                    fn (Customer $c) => ($c->lastGoodHealthCheck?->migrationsPending() ?? 0) > 0,
                )->count(),
            ],
        ]);
    }

    /**
     * Look at one shop now, rather than waiting for the hour.
     *
     * The same reading the schedule takes, written as another snapshot — never
     * over the top of one. Section 5 keeps them as a series so storage growing
     * over weeks stays visible, and a "refresh" that overwrote the last row
     * would quietly destroy that.
     */
    public function recheck(Customer $customer, ShopReader $reader): RedirectResponse
    {
        $reading = $reader->read($customer);

        HealthCheck::create([
            'customer_id' => $customer->id,
            'checked_at' => now(),
            ...$reading->toHealthCheck(),
        ]);

        return back()->with(
            $reading->reachable ? 'success' : 'warning',
            $reading->reachable
                ? "{$customer->name} answered. This is what it says right now."
                : "{$customer->name} could not be read: ".implode(' ', $reading->problems),
        );
    }
}
