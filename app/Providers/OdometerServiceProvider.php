<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Tobuli\Helpers\OdometerOptimizer;

class OdometerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(OdometerOptimizer::class, function ($app) {
            return new OdometerOptimizer();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
