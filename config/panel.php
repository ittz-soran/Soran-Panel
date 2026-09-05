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

        /*
         * Where a removed shop's last backup is kept — see ShopRemover.
         *
         * It must not be under either root above, because both are deleted by
         * the removal it is insurance against. Empty means the panel's own
         * storage folder, which is the right answer on this account.
         */
        'removed_root' => env('PANEL_REMOVED_SHOPS', ''),
    ],

    /*
     * Where composer is, for updating the code from the panel.
     *
     * A setting because the web account's PATH is rarely the shell's, and
     * "composer: not found" from a web page is a confusing way to learn that.
     * `~/composer.phar` is common on cPanel; so is a full path under /opt.
     */
    'composer' => env('PANEL_COMPOSER', 'composer'),

    'cpanel' => [
        'uapi' => env('PANEL_UAPI', '/usr/bin/uapi'),
        'prefix' => env('PANEL_CPANEL_PREFIX', ''),

        /*
         * The account's home folder, used to turn an absolute document root
         * into the home-relative one cPanel's `dir` wants.
         *
         * Optional, and set here it wins: App\Support\HomeFolder falls back to
         * $HOME and then to the account this process runs as. Leave it empty
         * unless the panel runs as one user on behalf of another.
         */
        'home' => env('PANEL_CPANEL_HOME', ''),
    ],

    /*
     * Who points a domain at a shop's public folder.
     *
     * `cpanel` does it through UAPI as part of creating the shop. `manual`
     * leaves it to a person and says so — the right answer on a laptop, and on
     * any host that is not cPanel.
     */
    'domain_maker' => [
        'driver' => env('PANEL_DOMAIN_MAKER', 'manual'),
    ],

    /*
     * Who publishes a shop's name, so the world can find it.
     *
     * ⚠️ Off by default, and that is a decision rather than caution. Turning it
     * on means keeping a token on this server that can rewrite where every one
     * of these domains points — a break-in could send the whole business
     * somewhere else, which is a larger thing than reading the panel's
     * database. Weigh it against thirty seconds of work per shop.
     *
     * If it is on, the token must be a Cloudflare SCOPED token with
     * Zone:DNS:Edit on one zone. Never a Global API Key.
     */
    'dns' => [
        'driver' => env('PANEL_DNS_MAKER', 'manual'),

        // What a shop's record points at. The account's shared IP, which
        // cPanel shows on its home page and `uapi DomainInfo` reports.
        'address' => env('PANEL_SERVER_IP', ''),

        'cloudflare' => [
            'token' => env('PANEL_CLOUDFLARE_TOKEN', ''),
            'zone_id' => env('PANEL_CLOUDFLARE_ZONE_ID', ''),

            // Orange cloud. Section 4 wants Full (strict), which needs the
            // origin to have a certificate first — so a brand new shop goes up
            // unproxied unless this is turned on deliberately.
            'proxied' => (bool) env('PANEL_CLOUDFLARE_PROXIED', false),
        ],
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
