<?php

namespace App\Providers;

use Illuminate\Http\Request;
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
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }

        Request::macro('safePerPage', function (int $default = 20, int $max = 50): int {
            return min($max, max(1, (int) $this->integer('per_page', $default)));
        });

        $caBundle = storage_path('certs/cacert.pem');

        if (is_file($caBundle)) {
            Http::globalOptions(['verify' => $caBundle]);
        } elseif ($this->app->environment('local')) {
            Http::globalOptions(['verify' => false]);
        }
    }
}
