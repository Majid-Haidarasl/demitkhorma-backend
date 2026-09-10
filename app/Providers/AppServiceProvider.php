<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $caBundle = storage_path('certs/cacert.pem');

        if (is_file($caBundle)) {
            Http::globalOptions(['verify' => $caBundle]);
        } elseif ($this->app->environment('local')) {
            Http::globalOptions(['verify' => false]);
        }
    }
}
