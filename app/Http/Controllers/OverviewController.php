<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * PANEL_DOC Section 9: "Only what needs Soran this week: licences running out,
 * storage near its limit, shops nobody has used. Everything else is a number."
 *
 * It cannot say any of that yet — there are no customers, no licences and no
 * health checks until build order steps 3 to 5. So it says so, rather than
 * showing zeroes that would read as "nothing needs you" when the truth is
 * "nothing has been recorded".
 */
class OverviewController extends Controller
{
    public function index(): View
    {
        return view('overview');
    }
}
