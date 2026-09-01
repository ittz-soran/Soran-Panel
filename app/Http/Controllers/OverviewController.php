<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\View\View;

/**
 * "Only what needs Soran this week" — PANEL_DOC Section 9.
 *
 * Three lists and some numbers, and the order matters: the lists are things to
 * do something about, the numbers are things to know. A screen that gives them
 * equal weight is a screen that gets skimmed.
 */
class OverviewController extends Controller
{
    public function index(): View
    {
        $days = config('panel.attention.licence_days');

        $with = ['currentLicence', 'latestHealthCheck', 'lastGoodHealthCheck'];

        return view('overview', [
            'days' => $days,
            'percent' => config('panel.attention.storage_percent'),
            'unusedDays' => config('panel.attention.unused_days'),

            'expiring' => Customer::with($with)->licenceExpiringWithin($days)
                // Soonest first: the one that has already run out is the one to
                // telephone about this morning.
                ->get()->sortBy(fn ($c) => $c->currentLicence?->expires_on)->values(),

            'full' => Customer::with($with)->storageOver()
                ->get()->sortByDesc(fn ($c) => $c->latestHealthCheck?->storagePercent())->values(),

            'unused' => Customer::with($with)->unusedFor()
                ->get()->sortBy(fn ($c) => $c->latestHealthCheck?->last_activity_at)->values(),

            'counts' => [
                'live' => Customer::live()->count(),

                /*
                 * Counted here, and never as the three lists added up.
                 *
                 * A shop can be on two of them at once — a licence running out
                 * on a shop nobody has opened is the ordinary case, not a rare
                 * one — and adding the lengths counted it twice. The Overview
                 * said three shops needed Soran while the Customers filter
                 * showed two, which is the exact disagreement the filter exists
                 * to avoid. Found by opening the page; the scopes agreed with
                 * each other perfectly, so the tests did not.
                 */
                'needing' => Customer::needsChasing()->count(),
                'all' => Customer::count(),
                'unreachable' => Customer::live()
                    ->whereHas('latestHealthCheck', fn ($check) => $check->where('reachable', false))
                    ->count(),
                'never_checked' => Customer::live()->doesntHave('healthChecks')->count(),
            ],

            // Integer dinars — PROJECT_DOC Section 2. What the live shops are
            // worth in a month, which is not the same as what has been paid.
            'monthly' => (int) Customer::live()->sum('monthly_fee'),
        ]);
    }
}
