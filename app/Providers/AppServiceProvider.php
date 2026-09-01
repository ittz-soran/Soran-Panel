<?php

namespace App\Providers;

use App\Contracts\ShopReader;
use App\Contracts\ShopWriter;
use App\Services\LocalShopReader;
use App\Services\LocalShopWriter;
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
    }

    public function boot(): void
    {
        //
    }
}
