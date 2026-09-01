<?php

namespace App\Support;

/**
 * The sidebar — PANEL_DOC Section 9's eight pages, in one place.
 *
 * Every page the panel will have is named here from the start, and each one
 * says whether it exists yet. A name with no route is drawn dimmed and
 * unclickable with the reason on it, rather than being left out or linked to a
 * 404: leaving it out hides the shape of the thing being built, and linking it
 * breaks Section 7's rule that the reason is on the screen before the press.
 *
 * As each build-order step lands, its entry gets its `route` and nothing else
 * changes.
 */
final class Navigation
{
    /**
     * @return list<array{label: string, icon: string, route: ?string, step: ?string}>
     */
    public static function items(): array
    {
        return [
            ['label' => 'Overview',       'icon' => 'bi-speedometer2',   'route' => 'overview', 'step' => null],
            ['label' => 'Customers',      'icon' => 'bi-shop',           'route' => null,       'step' => 'build order step 5'],
            ['label' => 'Subscriptions',  'icon' => 'bi-cash-coin',      'route' => null,       'step' => 'build order step 8'],
            ['label' => 'Health',         'icon' => 'bi-graph-up',    'route' => null,       'step' => 'build order step 4'],
            ['label' => 'What I changed', 'icon' => 'bi-clock-history',  'route' => null,       'step' => 'the table is built; the screen comes with the others'],
        ];
    }
}
