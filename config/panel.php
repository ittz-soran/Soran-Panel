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

];
