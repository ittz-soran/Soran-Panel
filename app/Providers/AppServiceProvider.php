<?php

namespace App\Providers;

use App\Contracts\DatabaseMaker;
use App\Contracts\DnsMaker;
use App\Contracts\DomainMaker;
use App\Contracts\ShopReader;
use App\Contracts\ShopWriter;
use App\Services\CloudflareDns;
use App\Services\CpanelDatabaseMaker;
use App\Services\CpanelDomainMaker;
use App\Services\DirectDatabaseMaker;
use App\Services\LocalShopReader;
use App\Services\LocalShopWriter;
use App\Services\ManualDns;
use App\Services\ManualDomainMaker;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * PANEL_DOC Section 8: every shop is on this same server, so the panel
         * reads them directly. If one is ever hosted elsewhere, this line is
         * what changes, and Section 8 is deliberate that it changes alone.
         */
        $this->app->bind(ShopReader::class, LocalShopReader::class);
        $this->app->bind(ShopWriter::class, LocalShopWriter::class);

        /*
         * Section 4 again: this cPanel account denies plain CREATE DATABASE, so
         * the server uses UAPI and everywhere else uses SQL. A setting rather
         * than a guess, because guessing wrong here fails at the one moment a
         * customer is standing in front of Soran.
         */
        $this->app->bind(DatabaseMaker::class, fn () => config('panel.database_maker.driver') === 'cpanel'
            ? new CpanelDatabaseMaker
            : new DirectDatabaseMaker);

        $this->app->bind(DomainMaker::class, fn () => config('panel.domain_maker.driver') === 'cpanel'
            ? new CpanelDomainMaker
            : new ManualDomainMaker);

        $this->app->bind(DnsMaker::class, fn () => config('panel.dns.driver') === 'cloudflare'
            ? new CloudflareDns
            : new ManualDns);
    }

    public function boot(): void
    {
        //
    }
}
