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
