<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The working screen, and one shop in full — PANEL_DOC Section 9.
 *
 * Both only read. Everything that changes a shop is Section 7's, and arrives
 * with build order steps 6 to 8; until then the danger zone shows what will be
 * there with the reason on the disabled button, which is Section 7's guard rail
 * rather than an apology for the button not working yet.
 */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $chasing = $request->query('show') === 'chasing';

        return view('customers.index', [
            'chasing' => $chasing,

            'customers' => Customer::query()
                // Both are one-of-many subqueries; without this the table runs
                // two more queries per row, which is fine at six customers and
                // is not the habit to build.
                ->with(['currentLicence', 'latestHealthCheck', 'lastGoodHealthCheck'])
                ->when($chasing, fn ($query) => $query->needsChasing())
                ->orderBy('name')
                ->get(),

            // Shown on the filter itself, so the count on the button and the
            // Overview's three lists are visibly the same number.
            'chasingCount' => Customer::needsChasing()->count(),
            'allCount' => Customer::count(),
        ]);
    }

    public function show(Customer $customer): View
    {
        return view('customers.show', [
            'customer' => $customer->load([
                'latestHealthCheck',
                'lastGoodHealthCheck',
                'currentLicence.issuedBy',

                // The full history, newest first — Section 9. A renewal is a
                // new row, so this is the record of every licence this shop has
                // ever run on, including the ones that were revoked.
                'licences' => fn ($query) => $query->with('issuedBy')
                    ->orderByDesc('issued_on')->orderByDesc('id'),
            ]),

            'paidUpTo' => $customer->paidUpTo(),

            // Enough to see a trend without turning the page into a chart.
            'recentChecks' => $customer->healthChecks()
                ->orderByDesc('checked_at')->limit(12)->get(),
        ]);
    }
}
