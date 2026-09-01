<?php

namespace App\Providers;

use App\Contracts\ShopReader;
use App\Services\LocalShopReader;
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
    }

    public function boot(): void
    {
        //
    }
}
