<?php

return [

    /*
    |---------------------------------------------------------------------------
    | The first operator
    |---------------------------------------------------------------------------
    |
    | There is no /register route (routes/auth.php says why), so `php artisan
    | db:seed` is the only way into a freshly migrated panel.
    |
    | Read here rather than with env() inside the seeder on purpose: once
    | `config:cache` has run — and it will have, on a server — env() outside a
    | config file returns null. A seeder that quietly created no operator on a
    | cached deploy would look like a working deploy right up until the moment
    | somebody tried to sign in.
    |
    */

    'first_operator' => [
        'name' => env('PANEL_ADMIN_NAME', 'Soran'),
        'email' => env('PANEL_ADMIN_EMAIL'),
        'password' => env('PANEL_ADMIN_PASSWORD'),
    ],

    /*
    |---------------------------------------------------------------------------
    | When a shop needs Soran
    |---------------------------------------------------------------------------
    |
    | The Overview shows "only what needs you this week" — PANEL_DOC Section 9 —
    | and Section 9 names the three things but not the numbers. They are here
    | rather than spelled through the code because they are judgement, not fact:
    | a shop at 79% of its disk is not meaningfully safer than one at 81%, and
    | the right line moves as Soran learns which warnings he acts on and which
    | he scrolls past. One place to change them, and the screens say what they
    | currently are.
    |
    */

    /*
    |---------------------------------------------------------------------------
    | Making a new shop's database
    |---------------------------------------------------------------------------
    |
    | PANEL_DOC Section 4: on this cPanel account plain `CREATE DATABASE` is
    | denied, and the way through is UAPI at /usr/bin/uapi. On Soran's own
    | machine there is no UAPI and SQL is all there is — so which one runs is a
    | setting, and the panel is developed on one and deployed on the other.
    |
    | `cpanel.prefix` is the account name cPanel puts in front of every database
    | and user it makes. The panel has to record the REAL name, or it cannot
    | read that shop again.
    |
    */

    'database_maker' => [
        'driver' => env('PANEL_DATABASE_MAKER', 'direct'),

        // The connection with rights to create databases — never the panel's
        // own, which deliberately has rights over one schema only.
        'connection' => env('PANEL_DATABASE_MAKER_CONNECTION', 'mysql'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Where shops live
    |---------------------------------------------------------------------------
    |
    | PANEL_DOC Section 4: document roots cannot leave public_html — cPanel was
    | tested and silently made its own folder inside it, ignoring the path that
    | was typed. So a shop's private folder and its public folder are in
    | different places, and both are settings rather than anything derived.
    |
    | `shared_artisan` is the one codebase every shop reads (Section 3). The
    | panel runs `shop:provision` through it, because that command lives in the
    | shop system beside install:sql and the bootstrap it defers to.
    |
    */

    'shops' => [
        'home_root' => env('PANEL_SHOPS_HOME', '/home/soransto/shops'),
        'public_root' => env('PANEL_SHOPS_PUBLIC', '/home/soransto/public_html'),
        'shared_artisan' => env('PANEL_SHARED_ARTISAN', '/home/soransto/smart-store/artisan'),
    ],

    'cpanel' => [
        'uapi' => env('PANEL_UAPI', '/usr/bin/uapi'),
        'prefix' => env('PANEL_CPANEL_PREFIX', ''),
    ],

    'attention' => [

        // A licence within this many days of its end — and anything already
        // past. Thirty days is one billing cycle: long enough to telephone
        // somebody, be told "next week", and telephone them again.
        'licence_days' => 30,

        // Storage this full. Eighty per cent leaves room to take a backup
        // before the disk is the problem, which is the whole point of knowing.
        'storage_percent' => 80,

        // A shop nobody has touched for this long. A fortnight covers a holiday
        // and a slow month; a week would flag every shop that closes for Eid.
        'unused_days' => 14,
    ],

];
